<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Contract;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClient;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiKeyResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for the contract suite: these tests talk to a real CrowdSec LAPI.
 *
 * The suite is opt-in. Without CROWDSEC_CONTRACT_TESTS=1 every test in it is
 * skipped, so a developer without a container is not blocked. CI sets the
 * variable and runs PHPUnit with --fail-on-skipped, so a skip there is a
 * failure — an opt-in gate that silently opts out is worse than no gate.
 *
 * Two identities are in play, and the difference is the whole point of the
 * suite:
 *
 *  - The **bouncer** API key, sent as X-Api-Key. It opens GET /v1/decisions
 *    and nothing else the bridge calls (LapiKeyResolver, Constitution
 *    principle IV), and bridgeClient() below speaks as it.
 *  - A **machine** (watcher) JWT, obtained via POST /v1/watchers/login. Since
 *    !64 the bridge holds this identity too (LapiMachineTokenProvider and the
 *    machine-role LapiHttpClient), because CrowdSec answers every other route
 *    the module needs with 401 for a bouncer key. The raw watcherToken() in
 *    this class remains test infrastructure — it seeds decisions and reads
 *    back payload shapes without depending on the code under test. The
 *    bridge's own machine role is exercised separately, through the
 *    production classes, in LapiMachineRoleContractTest.
 */
abstract class LapiContractTestCase extends TestCase
{
    /**
     * The origin the bridge stamps on every decision it creates. Seeding uses
     * the same value so the fixtures are indistinguishable from real bridge
     * output as far as LAPI is concerned.
     */
    protected const SEED_ORIGIN = 'typo3';

    private static ?string $watcherToken = null;

    private static int $ipCounter = 0;

    private ?GuzzleClient $psrClient = null;

    private ?HttpFactory $httpFactory = null;

    /**
     * The gate lives in setUp() rather than setUpBeforeClass() on purpose: a
     * class-level skip does not count towards PHPUnit's --fail-on-skipped
     * exit code, and that exit code is the only thing standing between a
     * misconfigured CI job and a green pipeline that tested nothing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ('1' !== getenv('CROWDSEC_CONTRACT_TESTS')) {
            self::markTestSkipped(
                'Contract suite is opt-in. Set CROWDSEC_CONTRACT_TESTS=1 and point CROWDSEC_LAPI_BASE_URL at a running CrowdSec LAPI.'
            );
        }
    }

    protected function tearDown(): void
    {
        $this->psrClient = null;
        $this->httpFactory = null;

        parent::tearDown();
    }

    /**
     * The production HTTP client, wired the way Configuration/Services.yaml
     * wires it: a Guzzle PSR-18 client, guzzlehttp/psr7 factories, and the
     * real LapiKeyResolver reading CROWDSEC_LAPI_KEY from the environment
     * (its documented source #2).
     */
    final protected function bridgeClient(): LapiHttpClient
    {
        return new LapiHttpClient(
            $this->psrClient(),
            $this->httpFactory(),
            $this->httpFactory(),
            new LapiKeyResolver(),
            self::baseUrl(),
        );
    }

    final protected static function baseUrl(): string
    {
        return rtrim(self::requiredEnv('CROWDSEC_LAPI_BASE_URL'), '/');
    }

    final protected static function bouncerKey(): string
    {
        return self::requiredEnv('CROWDSEC_LAPI_KEY');
    }

    /**
     * Sends a request without going through the bridge, for the cases where
     * the assertion is about the wire (a status code, a raw body) rather than
     * about what the bridge makes of it.
     *
     * @param array<string, string> $headers
     */
    final protected function sendRaw(
        string $method,
        string $path,
        array $headers = [],
        ?string $jsonBody = null,
    ): ResponseInterface {
        // The suite's own agent: the client default is TYPO3's on purpose (see
        // psrClient()), and the raw seeding login must not trip over it.
        $request = $this->httpFactory()
            ->createRequest($method, self::baseUrl() . '/' . ltrim($path, '/'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'crowdsec-bridge-contract-tests/1');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (null !== $jsonBody) {
            $request = $request
                ->withBody($this->httpFactory()->createStream($jsonBody))
                ->withHeader('Content-Type', 'application/json');
        }

        return $this->psrClient()->sendRequest($request);
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    final protected function sendAsWatcher(
        string $method,
        string $path,
        array $extraHeaders = [],
        ?string $jsonBody = null,
    ): ResponseInterface {
        return $this->sendRaw(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $this->watcherToken()] + $extraHeaders,
            $jsonBody,
        );
    }

    /**
     * Creates one decision through the watcher API and returns the id of the
     * alert that carries it.
     *
     * The payload is the one cscli sends (cmd/crowdsec-cli/clidecision/
     * decisions.go): every field the swagger model marks Required has to be
     * present or LAPI rejects the whole batch. This is deliberately NOT
     * DecisionDraft::toLapiPayload() — the test seeds fixtures, it does not
     * assert what the bridge builds.
     */
    final protected function seedDecision(
        string $value,
        string $scope = 'Ip',
        string $type = 'ban',
        string $duration = '24h',
        string $scenario = 'crowdsec-bridge contract test',
        string $origin = self::SEED_ORIGIN,
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $payload = [[
            'capacity' => 0,
            'decisions' => [[
                'duration' => $duration,
                'origin' => $origin,
                'scenario' => $scenario,
                'scope' => $scope,
                'type' => $type,
                'value' => $value,
            ]],
            'events' => [],
            'events_count' => 1,
            'leakspeed' => '0',
            'message' => $scenario,
            'scenario' => $scenario,
            'scenario_hash' => '',
            'scenario_version' => '',
            'simulated' => false,
            'source' => [
                'ip' => $value,
                'scope' => $scope,
                'value' => $value,
            ],
            'start_at' => $now,
            'stop_at' => $now,
        ]];

        $response = $this->sendAsWatcher(
            'POST',
            '/v1/alerts',
            [],
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        if (201 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Seeding a decision failed: POST /v1/alerts answered HTTP %d — %s', $response->getStatusCode(), (string) $response->getBody()));
        }

        $ids = self::decodeJsonList($response);
        $first = $ids[0] ?? null;

        if (!\is_string($first) && !\is_int($first)) {
            throw new \RuntimeException('POST /v1/alerts did not answer with a list of alert ids.');
        }

        return (int) $first;
    }

    /**
     * A documentation-range address (RFC 5737), handed out in sequence so two
     * seeds in the same run can never land on the same value — a collision
     * would make one test's fixture visible to another's assertions.
     */
    final protected static function uniqueTestIp(): string
    {
        ++self::$ipCounter;

        if (self::$ipCounter > 254) {
            throw new \RuntimeException('203.0.113.0/24 is exhausted; the contract suite needs a second seeding range.');
        }

        return \sprintf('203.0.113.%d', self::$ipCounter);
    }

    /**
     * @return array<int|string, mixed>
     */
    final protected static function decodeJsonArray(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Expected a JSON array/object, got: %s', $body));
        }

        return $decoded;
    }

    /**
     * @return list<mixed>
     */
    final protected static function decodeJsonList(ResponseInterface $response): array
    {
        $decoded = self::decodeJsonArray($response);

        return array_values($decoded);
    }

    private function psrClient(): GuzzleClient
    {
        if (null === $this->psrClient) {
            // Guzzle disables http_errors for PSR-18 sendRequest() calls, so a
            // 4xx comes back as a response rather than an exception — which is
            // what LapiHttpClient's status handling expects.
            //
            // The default User-Agent is TYPO3's, not Guzzle's. Guzzle's own
            // ("GuzzleHttp/7") happens to be name/version and passes CrowdSec's
            // watcher-login check; TYPO3's bare "TYPO3" does not. With Guzzle's
            // default this suite stayed green while every installation got a
            // 401 at login (2026-10-13). The bridge has to override the
            // agent itself, and only this default proves that it does.
            $this->psrClient = new GuzzleClient([
                'connect_timeout' => 5,
                'timeout' => 15,
                'headers' => ['User-Agent' => 'TYPO3'],
            ]);
        }

        return $this->psrClient;
    }

    private function httpFactory(): HttpFactory
    {
        if (null === $this->httpFactory) {
            $this->httpFactory = new HttpFactory();
        }

        return $this->httpFactory;
    }

    private function watcherToken(): string
    {
        if (null !== self::$watcherToken) {
            return self::$watcherToken;
        }

        $response = $this->sendRaw(
            'POST',
            '/v1/watchers/login',
            [],
            json_encode([
                'machine_id' => self::requiredEnv('CROWDSEC_CONTRACT_MACHINE_ID'),
                'password' => self::requiredEnv('CROWDSEC_CONTRACT_MACHINE_PASSWORD'),
            ], \JSON_THROW_ON_ERROR),
        );

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('Watcher login failed with HTTP %d — %s. The machine must exist and be validated; the container registers it from AGENT_USERNAME/AGENT_PASSWORD.', $response->getStatusCode(), (string) $response->getBody()));
        }

        $body = self::decodeJsonArray($response);
        $token = $body['token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new \RuntimeException('Watcher login returned no token.');
        }

        self::$watcherToken = $token;

        return $token;
    }

    private static function requiredEnv(string $name): string
    {
        $value = getenv($name);

        if (!\is_string($value) || '' === $value) {
            throw new \RuntimeException(\sprintf('Contract suite requires the environment variable %s.', $name));
        }

        return $value;
    }
}
