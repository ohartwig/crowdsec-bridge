<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Contract;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiCredential;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClient;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiKeyResolver;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineCredentialsResolver;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineTokenProvider;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiDecisionRepository;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiDecisionWriter;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiStatusRepository;
use Koh\CrowdsecBridge\Infrastructure\Time\SystemClock;

/**
 * The bridge's own machine (watcher) role, driven end to end through the
 * production classes against a real LAPI.
 *
 * Every other watcher request in this suite goes through sendAsWatcher(),
 * which is test infrastructure: it proves what the wire looks like, not that
 * the bridge can speak it. This class closes that gap. It builds
 * LapiMachineTokenProvider and the machine-role LapiHttpClient exactly the way
 * Configuration/Services.yaml wires them — credentials from secret files
 * (source #1 of Constitution principle IV's lookup order), the login at
 * POST /v1/watchers/login, the Bearer header on every call — and asserts that
 * this identity actually opens the routes the module hands it.
 *
 * The write and delete assertions matter most: the machine account is the
 * credential with the wider blast radius, and it is the one whose wiring must
 * not rest on inference.
 */
final class LapiMachineRoleContractTest extends LapiContractTestCase
{
    /** @var list<string> */
    private array $temporarySecretFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarySecretFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->temporarySecretFiles = [];

        parent::tearDown();
    }

    /**
     * The watcher login through the bridge's own class: credentials resolved
     * from secret files, exchanged at POST /v1/watchers/login, answered with a
     * JWT. Pins the wire format LapiMachineTokenProvider depends on — a
     * `token` field carrying three dot-separated segments.
     */
    public function testTheWatcherLoginYieldsAJwt(): void
    {
        $provider = $this->machineTokenProvider();

        $token = $provider->token();

        self::assertCount(3, explode('.', $token), 'POST /v1/watchers/login did not answer with a JWT in the token field.');

        // A forgotten token must be reacquired transparently — this is the
        // path LapiHttpClient takes after a 401, and it must not be the first
        // time it runs in production.
        $provider->forget();

        self::assertCount(3, explode('.', $provider->token()), 'Re-login after forget() did not yield a JWT.');
    }

    /**
     * The heartbeat is the status panel's evidence. Driven through
     * LapiStatusRepository so the assertion covers the whole machine-role
     * stack: resolver, login, Bearer header, empty-body handling.
     */
    public function testTheMachineRoleOpensTheHeartbeat(): void
    {
        $repository = new LapiStatusRepository($this->machineClient(), new LapiResponseParser());

        $status = $repository->findStatus(new \DateTimeImmutable());

        self::assertTrue(
            $status->isReachable,
            \sprintf('The machine role could not open /v1/heartbeat (lastError: %s).', $status->lastError ?? 'none'),
        );
    }

    /**
     * Creating a decision is a machine-role write: the bridge posts the alert
     * that carries it. This is the one path where the payload is the bridge's
     * own (DecisionDraft::toLapiPayload()), not the seeding fixture — so this
     * assertion, unlike seedDecision(), fails when the bridge's payload stops
     * satisfying CrowdSec's Required fields.
     */
    public function testTheMachineRoleCreatesADecisionWithTheBridgesOwnPayload(): void
    {
        $ip = self::uniqueTestIp();
        $writer = new LapiDecisionWriter($this->machineClient(), new SystemClock());

        $alertId = $writer->add(new DecisionDraft(
            DecisionScope::Ip,
            DecisionType::Ban,
            $ip,
            new Duration('24h'),
            new Reason('machine-role contract test'),
        ));

        self::assertGreaterThan(0, $alertId->toInt(), 'POST /v1/alerts did not answer with a usable alert id.');

        // Read back with the bouncer role: the decision must exist and carry
        // the origin the audit trail depends on.
        $decision = $this->bouncerDecisionRepository()->findByIp(new IpAddress($ip));

        self::assertNotNull($decision, \sprintf('The decision written for %s is not readable through GET /v1/decisions.', $ip));
        self::assertSame(DecisionDraft::FIXED_ORIGIN, $decision->origin);
    }

    /**
     * Deleting is the machine role's most consequential entitlement, so it is
     * asserted end to end: seed, resolve the decision id with the bouncer
     * role, delete with the machine role, verify the row is gone.
     */
    public function testTheMachineRoleDeletesADecision(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        $repository = $this->bouncerDecisionRepository();
        $decision = $repository->findByIp(new IpAddress($ip));
        self::assertNotNull($decision, \sprintf('No decision found for %s to delete.', $ip));

        $writer = new LapiDecisionWriter($this->machineClient(), new SystemClock());

        self::assertTrue($writer->delete($decision->id), \sprintf('DELETE /v1/decisions/%d did not report a deletion.', $decision->id->toInt()));
        self::assertNull($repository->findByIp(new IpAddress($ip)), 'The decision is still readable after the machine role deleted it.');
    }

    /**
     * The machine-role client exactly as Configuration/Services.yaml builds
     * `crowdsec_bridge.lapi_client.machine`.
     */
    private function machineClient(): LapiHttpClient
    {
        $factory = new HttpFactory();

        return new LapiHttpClient(
            $this->guzzle(),
            $factory,
            $factory,
            new LapiKeyResolver(),
            self::baseUrl(),
            LapiCredential::MachineToken,
            $this->machineTokenProvider(),
        );
    }

    /**
     * Credentials come from freshly written secret files, so the test walks
     * source #1 of the Constitution's lookup order — the one production is
     * expected to use — rather than mutating process environment.
     */
    private function machineTokenProvider(): LapiMachineTokenProvider
    {
        $factory = new HttpFactory();

        return new LapiMachineTokenProvider(
            $this->guzzle(),
            $factory,
            $factory,
            new LapiMachineCredentialsResolver(
                $this->secretFile('machine-id', self::contractEnv('CROWDSEC_CONTRACT_MACHINE_ID')),
                $this->secretFile('machine-password', self::contractEnv('CROWDSEC_CONTRACT_MACHINE_PASSWORD')),
            ),
            new SystemClock(),
            self::baseUrl(),
        );
    }

    private function bouncerDecisionRepository(): LapiDecisionRepository
    {
        return new LapiDecisionRepository($this->bridgeClient(), new LapiResponseParser());
    }

    private function guzzle(): GuzzleClient
    {
        // Same shape as LapiContractTestCase::psrClient(), which is private
        // there on purpose: PSR-18 mode, so 4xx answers stay responses. TYPO3's
        // default agent too, for the reason given there.
        return new GuzzleClient([
            'connect_timeout' => 5,
            'timeout' => 15,
            'headers' => ['User-Agent' => 'TYPO3'],
        ]);
    }

    private function secretFile(string $label, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crowdsec-contract-' . $label . '-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary secret file.');
        }
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(\sprintf('Could not write the temporary secret file %s.', $path));
        }

        $this->temporarySecretFiles[] = $path;

        return $path;
    }

    private static function contractEnv(string $name): string
    {
        $value = getenv($name);

        if (!\is_string($value) || '' === $value) {
            throw new \RuntimeException(\sprintf('Contract suite requires the environment variable %s.', $name));
        }

        return $value;
    }
}
