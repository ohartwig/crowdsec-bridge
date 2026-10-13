<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineCredentialsResolver;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineTokenProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The login the bridge never used to do.
 *
 * A machine token is the only credential CrowdSec accepts on /v1/heartbeat,
 * /v1/alerts and DELETE /v1/decisions/{id}. It is also a password sent over the
 * wire and a bearer token held in memory, so the two things worth pinning are
 * that it is not fetched more often than necessary and that a bad answer is not
 * turned into a plausible-looking token.
 */
final class LapiMachineTokenProviderTest extends TestCase
{
    private string $idFile;
    private string $passwordFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idFile = self::tempFileContaining('typo3-bridge');
        $this->passwordFile = self::tempFileContaining('machine-password');

        putenv('CROWDSEC_LAPI_MACHINE_ID');
        putenv('CROWDSEC_LAPI_MACHINE_PASSWORD');
    }

    protected function tearDown(): void
    {
        foreach ([$this->idFile, $this->passwordFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function theCredentialsAreSentAsJsonToTheWatcherLoginRoute(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"jwt","expire":"2099-01-01T00:00:00Z"}'),
        );

        self::assertSame('jwt', $this->provider($http)->token());

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('http://lapi:8080/v1/watchers/login', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        // CrowdSec answers a bare "TYPO3" agent with 401 after accepting the password.
        self::assertCount(2, explode('/', $request->getHeaderLine('User-Agent')));
        self::assertSame(
            ['machine_id' => 'typo3-bridge', 'password' => 'machine-password'],
            json_decode((string) $request->getBody(), true),
        );
    }

    /**
     * One login per token lifetime. A module page issues several LAPI calls,
     * and logging in before each of them would multiply both the round trips
     * and the number of password transmissions by the number of widgets.
     */
    #[Test]
    public function aTokenThatIsStillValidIsNotFetchedAgain(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"jwt","expire":"2099-01-01T00:00:00Z"}'),
        );

        $provider = $this->provider($http);

        self::assertSame('jwt', $provider->token());
        self::assertSame('jwt', $provider->token());
        self::assertCount(1, $http->requests);
    }

    #[Test]
    public function anExpiredTokenIsReplaced(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"old","expire":"2026-08-01T09:00:00Z"}'),
            new Response(200, [], '{"token":"new","expire":"2099-01-01T00:00:00Z"}'),
        );

        $provider = $this->provider($http);

        self::assertSame('old', $provider->token());
        self::assertSame('new', $provider->token(), 'the first token expired an hour before "now"');
    }

    /**
     * The margin exists so a token is never handed out with seconds left on it:
     * the request it authenticates would be refused, and the refusal would look
     * like a wrong password rather than a race.
     */
    #[Test]
    public function aTokenExpiringWithinTheSafetyMarginCountsAsSpent(): void
    {
        $http = new RecordingPsr18Client(
            // 30 seconds after the fixed "now" — inside the 60-second margin.
            new Response(200, [], '{"token":"almost-gone","expire":"2026-08-01T10:00:30Z"}'),
            new Response(200, [], '{"token":"fresh","expire":"2099-01-01T00:00:00Z"}'),
        );

        $provider = $this->provider($http);

        self::assertSame('almost-gone', $provider->token());
        self::assertSame('fresh', $provider->token());
    }

    #[Test]
    public function forgettingTheTokenForcesTheNextCallToLogInAgain(): void
    {
        $http = new RecordingPsr18Client(
            new Response(200, [], '{"token":"one","expire":"2099-01-01T00:00:00Z"}'),
            new Response(200, [], '{"token":"two","expire":"2099-01-01T00:00:00Z"}'),
        );

        $provider = $this->provider($http);

        self::assertSame('one', $provider->token());
        $provider->forget();
        self::assertSame('two', $provider->token());
    }

    /**
     * A refused login is a credential problem the operator can act on. It must
     * not arrive as "LAPI is unavailable", which sends them to look at the
     * container instead of at the machine account.
     */
    #[Test]
    #[DataProvider('refusalStatuses')]
    public function arefusedLoginIsAnAuthenticationFailure(int $status): void
    {
        $provider = $this->provider(RecordingPsr18Client::answering($status));

        $this->expectException(LapiAuthenticationException::class);

        $provider->token();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function refusalStatuses(): array
    {
        return ['unauthorized' => [401], 'forbidden' => [403]];
    }

    #[Test]
    public function aLoginWithoutAConfiguredAccountNamesTheMissingSetting(): void
    {
        $provider = new LapiMachineTokenProvider(
            RecordingPsr18Client::answering(),
            new HttpFactory(),
            new HttpFactory(),
            new LapiMachineCredentialsResolver('/nonexistent/id', '/nonexistent/password'),
            self::clock(),
            'http://lapi:8080',
        );

        $this->expectException(LapiAuthenticationException::class);
        $this->expectExceptionMessageMatches('/CROWDSEC_LAPI_MACHINE_ID/');

        $provider->token();
    }

    /**
     * An answer without a token must not become an empty bearer header. The
     * request would then be refused for a reason that has nothing to do with
     * the credential, and the trail back to the login would be gone.
     */
    #[Test]
    public function anAnswerWithoutATokenIsRefused(): void
    {
        $provider = $this->provider(new RecordingPsr18Client(new Response(200, [], '{"code":200}')));

        $this->expectException(LapiUnavailableException::class);

        $provider->token();
    }

    #[Test]
    public function aMalformedAnswerIsRefused(): void
    {
        $provider = $this->provider(new RecordingPsr18Client(new Response(200, [], '{not json')));

        $this->expectException(LapiUnavailableException::class);

        $provider->token();
    }

    private function provider(RecordingPsr18Client $http): LapiMachineTokenProvider
    {
        return new LapiMachineTokenProvider(
            $http,
            new HttpFactory(),
            new HttpFactory(),
            new LapiMachineCredentialsResolver($this->idFile, $this->passwordFile),
            self::clock(),
            'http://lapi:8080',
        );
    }

    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-08-01T10:00:00Z');
            }
        };
    }

    private static function tempFileContaining(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lapi-machine-');
        file_put_contents($path, $contents . "\n");

        return $path;
    }
}
