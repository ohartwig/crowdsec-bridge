<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiStatusRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The status panel is the first thing an operator looks at when bans stop
 * working, so "why is it unreachable" has to be answered accurately: a wrong
 * API key and a dead host need different actions, and reporting one as the
 * other sends the operator looking in the wrong place.
 *
 * A failing heartbeat must never escape as an exception either — the module
 * page would then be blank instead of saying that CrowdSec is down.
 */
final class LapiStatusRepositoryTest extends TestCase
{
    private function repositoryFailingWith(\Throwable $error): LapiStatusRepository
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('get')->willThrowException($error);

        return new LapiStatusRepository($client, new LapiResponseParser());
    }

    private function checkedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-30T08:00:00Z');
    }

    /**
     * A rejected credential is a configuration problem, not an outage.
     */
    #[Test]
    public function aRejectedCredentialIsReportedAsAnAuthenticationFailure(): void
    {
        $status = $this->repositoryFailingWith(LapiAuthenticationException::invalidApiKey())
            ->findStatus($this->checkedAt());

        self::assertFalse($status->isReachable);
        self::assertSame('auth_failed', $status->lastError);
        self::assertEquals($this->checkedAt(), $status->checkedAt);
    }

    /**
     * The heartbeat is a watcher route, so the commonest reason it fails is that
     * no machine account is configured at all — and that is a different fix from
     * a rejected key. Reporting it as "authentication failed" sent the operator
     * to rotate a bouncer key that was working perfectly well; the decisions
     * list next to the panel keeps proving it.
     */
    #[Test]
    public function aMissingMachineAccountIsNotReportedAsARejectedKey(): void
    {
        $status = $this->repositoryFailingWith(LapiAuthenticationException::machineAccountMissing())
            ->findStatus($this->checkedAt());

        self::assertFalse($status->isReachable);
        self::assertSame('no_machine_account', $status->lastError);
    }

    /**
     * A timeout points at the host or the network; an invalid answer points at
     * something answering that is not LAPI. The two are told apart by the
     * message text, which is worth pinning precisely because it is fragile.
     */
    #[Test]
    public function aTimeoutIsDistinguishedFromAMalformedAnswer(): void
    {
        $timedOut = $this->repositoryFailingWith(
            LapiUnavailableException::invalidResponse('request timed out after 5s'),
        )->findStatus($this->checkedAt());

        self::assertSame('timeout', $timedOut->lastError);

        $malformed = $this->repositoryFailingWith(
            LapiUnavailableException::invalidResponse('heartbeat was not an object'),
        )->findStatus($this->checkedAt());

        self::assertSame('invalid_response', $malformed->lastError);
    }

    /**
     * Whatever goes wrong upstream, the caller gets a status object back. An
     * exception here would take the whole backend module page down.
     */
    #[Test]
    public function aFailingHeartbeatNeverEscapesAsAnException(): void
    {
        foreach ([
            LapiAuthenticationException::invalidApiKey(),
            LapiUnavailableException::invalidResponse('timed out'),
            LapiUnavailableException::invalidResponse('garbage'),
        ] as $error) {
            $status = $this->repositoryFailingWith($error)->findStatus($this->checkedAt());

            self::assertFalse($status->isReachable, $error->getMessage());
        }
    }

    #[Test]
    public function aHealthyHeartbeatIsParsed(): void
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('get')->willReturnCallback(
            static fn(string $path): array => ['/v1/heartbeat' === $path ? 'ok' : 'unexpected' => true],
        );

        $status = new LapiStatusRepository($client, new LapiResponseParser())
            ->findStatus($this->checkedAt());

        self::assertTrue($status->isReachable, 'a heartbeat that answers at all means LAPI is up');
        self::assertEquals($this->checkedAt(), $status->checkedAt);
    }

    /**
     * The bouncer name is configurable and ends up on the status panel; a
     * repository that dropped it would leave the operator unable to tell which
     * registration the panel is talking about.
     */
    #[Test]
    public function theConfiguredBouncerNameIsCarriedIntoTheStatus(): void
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('get')->willReturn(['ok' => true]);

        $status = new LapiStatusRepository($client, new LapiResponseParser(), 'edge-bouncer')
            ->findStatus($this->checkedAt());

        self::assertSame('edge-bouncer', $status->bouncerName);
    }
}
