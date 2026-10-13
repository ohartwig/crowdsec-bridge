<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Logs the configured machine (watcher) account in and keeps the resulting JWT
 * for as long as CrowdSec says it is good for.
 *
 * `POST /v1/watchers/login` is the only way to obtain the credential that
 * `/v1/heartbeat`, `/v1/alerts` and `DELETE /v1/decisions/{id}` require, and it
 * is deliberately not routed through LapiHttpClient: that class exists to speak
 * as an already-authenticated caller, and having it call back into the thing
 * that authenticates it would be a cycle.
 *
 * The token is held in memory only. Under FrankenPHP worker mode a process
 * survives many requests, so the cache is worth having — and so is the expiry
 * check, because a token that outlived its window comes back as 401 from every
 * route at once.
 */
final class LapiMachineTokenProvider
{
    private const LOGIN_PATH = '/v1/watchers/login';

    /**
     * Treat a token as spent a minute before CrowdSec does. The alternative is
     * racing the clock on every request that lands near the boundary and
     * discovering the expiry as a 401 in the middle of a delete.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    private ?string $token = null;

    private ?int $expiresAt = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LapiMachineCredentialsResolver $credentialsResolver,
        private readonly ClockInterface $clock,
        private readonly string $baseUrl,
    ) {}

    /**
     * @throws LapiAuthenticationException when no machine account is configured or CrowdSec refuses it
     * @throws LapiUnavailableException    when the login request itself fails
     */
    public function token(): string
    {
        $now = $this->clock->now()->getTimestamp();

        if (null !== $this->token && null !== $this->expiresAt && $this->expiresAt > $now) {
            return $this->token;
        }

        return $this->login();
    }

    /**
     * Drops the cached token so the next call logs in again. Called by
     * LapiHttpClient when a request that carried this token was answered 401 —
     * the only reliable signal that CrowdSec disagrees with our expiry.
     */
    public function forget(): void
    {
        $this->token = null;
        $this->expiresAt = null;
    }

    private function login(): string
    {
        $credentials = $this->credentialsResolver->resolve();
        if (null === $credentials) {
            throw LapiAuthenticationException::machineAccountMissing();
        }

        $payload = json_encode([
            'machine_id' => $credentials->machineId,
            'password' => $credentials->password,
        ], \JSON_THROW_ON_ERROR);

        $request = $this->requestFactory
            ->createRequest('POST', rtrim($this->baseUrl, '/') . self::LOGIN_PATH)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', LapiUserAgent::header())
            ->withBody($this->streamFactory->createStream($payload));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $networkError) {
            throw LapiUnavailableException::timeout($networkError->getMessage());
        }

        $status = $response->getStatusCode();
        if (401 === $status || 403 === $status) {
            throw LapiAuthenticationException::machineLoginRejected($status);
        }
        if (200 !== $status) {
            throw LapiUnavailableException::invalidResponse(\sprintf('watcher login answered HTTP %d', $status));
        }

        return $this->rememberTokenFrom((string) $response->getBody());
    }

    private function rememberTokenFrom(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $caught) {
            throw LapiUnavailableException::invalidResponse('watcher login returned malformed JSON: ' . $caught->getMessage());
        }

        if (!\is_array($decoded) || !isset($decoded['token']) || !\is_string($decoded['token']) || '' === $decoded['token']) {
            throw LapiUnavailableException::invalidResponse('watcher login returned no token');
        }

        $this->token = $decoded['token'];
        $this->expiresAt = $this->parseExpiry($decoded['expire'] ?? null);

        return $this->token;
    }

    /**
     * CrowdSec answers with an RFC-3339 `expire`. If it is missing or
     * unreadable the token is used for a single call and then discarded — a
     * short-lived token is a performance question, an over-long one is a
     * correctness question.
     */
    private function parseExpiry(mixed $raw): int
    {
        if (!\is_string($raw) || '' === $raw) {
            return $this->clock->now()->getTimestamp();
        }

        try {
            $expiry = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return $this->clock->now()->getTimestamp();
        }

        return $expiry->getTimestamp() - self::EXPIRY_MARGIN_SECONDS;
    }
}
