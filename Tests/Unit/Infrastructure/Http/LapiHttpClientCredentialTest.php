<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiCredential;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClient;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiKeyResolver;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineCredentialsResolver;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineTokenProvider;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiUserAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which credential goes out on the wire, and what happens when CrowdSec refuses
 * it.
 *
 * Until now the client sent `X-Api-Key` on every request, and every test agreed
 * with it — because every test was answered by a double that never checked.
 * A real CrowdSec puts /v1/heartbeat, /v1/alerts and DELETE /v1/decisions/{id}
 * behind its JWT middleware and answers all three with 401 for a bouncer key,
 * which is what Tests/Contract/LapiAuthSchemeContractTest records. Three of the
 * module's four features were therefore not merely degraded: they never reached
 * their endpoint at all.
 */
final class LapiHttpClientCredentialTest extends TestCase
{
    private string $keyFile;
    private string $machineIdFile;
    private string $machinePasswordFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Real resolvers backed by real files: both classes read from disk
        // first, and a double would have skipped the code that does it.
        $this->keyFile = self::tempFileContaining('bouncer-key');
        $this->machineIdFile = self::tempFileContaining('typo3-bridge');
        $this->machinePasswordFile = self::tempFileContaining('machine-password');

        // The resolver falls through to the environment, and an inherited
        // variable would decide the "nothing configured" case for us.
        putenv('CROWDSEC_LAPI_MACHINE_ID');
        putenv('CROWDSEC_LAPI_MACHINE_PASSWORD');
    }

    protected function tearDown(): void
    {
        foreach ([$this->keyFile, $this->machineIdFile, $this->machinePasswordFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private static function tempFileContaining(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lapi-');
        file_put_contents($path, $contents . "\n");

        return $path;
    }

    #[Test]
    public function aBouncerClientIdentifiesItselfWithTheApiKeyHeader(): void
    {
        $http = new RecordingPsr18Client(new Response(200, [], '[]'));

        $this->client($http, LapiCredential::BouncerApiKey)->get('/v1/decisions');

        self::assertSame('bouncer-key', $http->requests[0]->getHeaderLine('X-Api-Key'));
        self::assertFalse($http->requests[0]->hasHeader('Authorization'), 'a bouncer has no JWT to offer');
    }

    /**
     * Not TYPO3's bare "TYPO3": CrowdSec refuses that on the JWT routes and
     * logs a warning for it on every bouncer request.
     */
    #[Test]
    public function everyRequestCarriesTheBridgeUserAgent(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"jwt-one","expire":"2099-01-01T00:00:00Z"}'),
            new Response(200, [], '[]'),
            new Response(200, [], '[]'),
        );

        $this->client($http, LapiCredential::MachineToken, $http)->get('/v1/alerts');
        $this->client($http, LapiCredential::BouncerApiKey)->get('/v1/decisions');

        self::assertCount(3, $http->requests);
        foreach ($http->requests as $request) {
            self::assertSame(LapiUserAgent::header(), $request->getHeaderLine('User-Agent'));
        }
    }

    /**
     * The machine identity travels as a bearer token, and the API key must not
     * ride along: offering both would suggest a fallback that CrowdSec does not
     * have, because it chooses its middleware by route and looks at one header.
     */
    #[Test]
    public function aMachineClientIdentifiesItselfWithABearerToken(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"jwt-one","expire":"2099-01-01T00:00:00Z"}'),
            new Response(200, [], '[]'),
        );

        $this->client($http, LapiCredential::MachineToken, $http)->get('/v1/alerts');

        $login = $http->requests[0];
        self::assertSame('POST', $login->getMethod());
        self::assertStringEndsWith('/v1/watchers/login', (string) $login->getUri());

        $call = $http->requests[1];
        self::assertSame('Bearer jwt-one', $call->getHeaderLine('Authorization'));
        self::assertFalse($call->hasHeader('X-Api-Key'), 'the bouncer key has no business on a watcher route');
    }

    /**
     * A JWT has a lifetime, and under FrankenPHP worker mode the process
     * outlives it. A 401 on a machine route is therefore first read as "the
     * token is spent", not as "the credential is wrong".
     */
    #[Test]
    public function anExpiredMachineTokenIsExchangedOnceAndTheCallRetried(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"stale","expire":"2099-01-01T00:00:00Z"}'),
            new Response(401),
            new Response(200, [], '{"token":"fresh","expire":"2099-01-01T00:00:00Z"}'),
            new Response(200, [], '[]'),
        );

        $this->client($http, LapiCredential::MachineToken, $http)->get('/v1/alerts');

        self::assertCount(4, $http->requests);
        self::assertSame('Bearer stale', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('Bearer fresh', $http->requests[3]->getHeaderLine('Authorization'));
    }

    /**
     * The retry happens once. A credential that is refused twice is wrong, not
     * stale, and a second exchange would turn a configuration error into a
     * login loop against a service whose job is to notice too many logins.
     */
    #[Test]
    public function aTokenThatIsRefusedTwiceIsReportedRatherThanExchangedAgain(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"one","expire":"2099-01-01T00:00:00Z"}'),
            new Response(401),
            new Response(200, [], '{"token":"two","expire":"2099-01-01T00:00:00Z"}'),
            new Response(401),
        );

        $client = $this->client($http, LapiCredential::MachineToken, $http);

        $this->expectException(LapiAuthenticationException::class);
        // Not "check the API key": the login worked, so the account exists and
        // the operator would be sent to rotate the wrong secret.
        $this->expectExceptionMessageMatches('/refused the machine token on \/v1\/alerts/');

        try {
            $client->get('/v1/alerts');
        } finally {
            self::assertCount(4, $http->requests, 'exactly one re-login');
        }
    }

    /**
     * An installation that only ever wanted the decision list has no machine
     * account, and must be told that rather than shown a rejected login. The
     * message names the setting; "authentication failed" would send the
     * operator to check a password that does not exist.
     */
    #[Test]
    public function aMachineRouteWithoutAConfiguredAccountSaysSoInsteadOfGuessing(): void
    {
        $http = RecordingPsr18Client::answering();

        $client = new LapiHttpClient(
            $http,
            new HttpFactory(),
            new HttpFactory(),
            new LapiKeyResolver($this->keyFile),
            'http://lapi:8080',
            LapiCredential::MachineToken,
            $this->tokenProvider($http, machineId: null),
        );

        $this->expectException(LapiAuthenticationException::class);
        $this->expectExceptionMessageMatches('/No CrowdSec machine account configured/');

        $client->get('/v1/heartbeat');
    }

    /**
     * The five-argument construction predates the credential split and is what
     * Tests/Contract/LapiContractTestCase builds. It has to keep meaning "speak
     * as the bouncer", or the contract suite would start asserting something
     * other than what it says it asserts.
     */
    #[Test]
    public function theCredentialDefaultsToTheBouncerKey(): void
    {
        $http = new RecordingPsr18Client(new Response(200, [], '[]'));

        new LapiHttpClient(
            $http,
            new HttpFactory(),
            new HttpFactory(),
            new LapiKeyResolver($this->keyFile),
            'http://lapi:8080',
        )->get('/v1/decisions');

        self::assertSame('bouncer-key', $http->requests[0]->getHeaderLine('X-Api-Key'));
    }

    private function client(
        RecordingPsr18Client $http,
        LapiCredential $credential,
        ?RecordingPsr18Client $loginTransport = null,
    ): LapiHttpClient {
        return new LapiHttpClient(
            $http,
            new HttpFactory(),
            new HttpFactory(),
            new LapiKeyResolver($this->keyFile),
            'http://lapi:8080',
            $credential,
            null === $loginTransport ? null : $this->tokenProvider($loginTransport),
        );
    }

    private function tokenProvider(
        RecordingPsr18Client $http,
        ?string $machineId = 'typo3-bridge',
    ): LapiMachineTokenProvider {
        $resolver = null === $machineId
            ? new LapiMachineCredentialsResolver('/nonexistent/id', '/nonexistent/password')
            : new LapiMachineCredentialsResolver($this->machineIdFile, $this->machinePasswordFile);

        return new LapiMachineTokenProvider(
            $http,
            new HttpFactory(),
            new HttpFactory(),
            $resolver,
            new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-08-01T10:00:00Z');
                }
            },
            'http://lapi:8080',
        );
    }
}
