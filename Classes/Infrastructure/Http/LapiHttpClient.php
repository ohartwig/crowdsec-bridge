<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiNotFoundException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiCredential;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin PSR-18 wrapper around the CrowdSec LAPI. Translates HTTP-level failures into
 * domain exceptions. The actual TYPO3-provided Guzzle client is injected by the DI
 * container — this class does not construct any HTTP infrastructure itself.
 *
 * One instance speaks as exactly one identity, chosen at wiring time rather than
 * per call: CrowdSec's route/credential split is a property of the route, and a
 * repository that had to remember which header its endpoint wants would get it
 * wrong the first time a new endpoint was added. Configuration/Services.yaml
 * therefore builds two of these — a bouncer one for LapiDecisionRepository and a
 * machine one for the alert, status and write paths.
 *
 * The default is the bouncer key, so the five-argument construction that
 * predates the split keeps its old behaviour and the read path needs no machine
 * account at all.
 */
final readonly class LapiHttpClient implements LapiClientInterface
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private LapiKeyResolver $keyResolver,
        private string $baseUrl,
        private LapiCredential $credential = LapiCredential::BouncerApiKey,
        private ?LapiMachineTokenProvider $machineTokens = null,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $response = $this->dispatch('GET', $path, $query);
        $body = (string) $response->getBody();
        if ('' === $body || 'null' === $body) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $caught) {
            throw LapiUnavailableException::invalidResponse('malformed JSON body: ' . $caught->getMessage());
        }

        if (!\is_array($decoded)) {
            throw LapiUnavailableException::invalidResponse('JSON body is not an array/object');
        }

        return $decoded;
    }

    public function post(string $path, array $body): array
    {
        $encoded = json_encode($body, \JSON_THROW_ON_ERROR);
        $response = $this->dispatch('POST', $path, [], $encoded);
        $rawBody = (string) $response->getBody();
        if ('' === $rawBody || 'null' === $rawBody) {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $caught) {
            throw LapiUnavailableException::invalidResponse('malformed JSON body: ' . $caught->getMessage());
        }
        if (!\is_array($decoded)) {
            throw LapiUnavailableException::invalidResponse('JSON body is not an array/object');
        }

        return $decoded;
    }

    public function delete(string $path): bool
    {
        try {
            $response = $this->dispatch('DELETE', $path, []);
        } catch (LapiNotFoundException) {
            // 404 means the decision was already removed — domain-meaningful "not found".
            return false;
        }

        return 200 === $response->getStatusCode();
    }

    /**
     * @param array<string, scalar> $query
     */
    private function dispatch(string $method, string $path, array $query, ?string $body = null): \Psr\Http\Message\ResponseInterface
    {
        $url = $this->buildUrl($path, $query);
        $request = $this->requestFactory
            ->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', LapiUserAgent::header());

        if (null !== $body) {
            // Refactor C10: PSR-7 requests are immutable — mutating the body stream
            // directly relies on implementation-specific behaviour. Attach a new
            // body stream via withBody() instead.
            $request = $request
                ->withBody($this->streamFactory->createStream($body))
                ->withHeader('Content-Type', 'application/json');
        }

        $response = $this->send($this->authorise($request));

        $status = $response->getStatusCode();

        // A machine token has a lifetime, and CrowdSec is the authority on when
        // it ends. One re-login and one retry: if the second attempt is refused
        // as well the credential is wrong, not stale, and retrying again would
        // only turn a configuration error into a login loop.
        if (401 === $status && LapiCredential::MachineToken === $this->credential && null !== $this->machineTokens) {
            $this->machineTokens->forget();
            $response = $this->send($this->authorise($request));
            $status = $response->getStatusCode();
        }

        if (401 === $status) {
            // Which credential was turned away decides what the operator has to
            // go and look at, so the two must not share a message.
            throw LapiCredential::MachineToken === $this->credential ? LapiAuthenticationException::machineTokenRejected($path) : LapiAuthenticationException::invalidApiKey();
        }
        if (404 === $status) {
            throw LapiNotFoundException::forPath($path);
        }
        if ($status >= 500 || 0 === $status) {
            throw LapiUnavailableException::serverError($status);
        }
        if ($status >= 400) {
            throw LapiUnavailableException::invalidResponse(\sprintf('LAPI returned HTTP %d', $status));
        }

        return $response;
    }

    private function send(RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $networkError) {
            throw LapiUnavailableException::timeout($networkError->getMessage());
        }
    }

    /**
     * Adds the one header this instance's identity is carried in. Never both:
     * CrowdSec picks its middleware by route, and a request that offers two
     * credentials still only ever has one of them looked at.
     */
    private function authorise(RequestInterface $request): RequestInterface
    {
        return match ($this->credential) {
            LapiCredential::BouncerApiKey => $request->withHeader('X-Api-Key', $this->keyResolver->resolve()),
            LapiCredential::MachineToken => $request->withHeader('Authorization', 'Bearer ' . $this->machineToken()),
        };
    }

    private function machineToken(): string
    {
        if (null === $this->machineTokens) {
            throw LapiAuthenticationException::machineAccountMissing();
        }

        return $this->machineTokens->token();
    }

    /**
     * @param array<string, scalar> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $base = rtrim($this->baseUrl, '/');
        $url = $base . '/' . ltrim($path, '/');
        if ([] !== $query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
