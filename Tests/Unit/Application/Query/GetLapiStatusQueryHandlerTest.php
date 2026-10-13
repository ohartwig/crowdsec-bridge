<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Query;

use Koh\CrowdsecBridge\Application\Query\GetLapiStatusQuery;
use Koh\CrowdsecBridge\Application\Query\GetLapiStatusQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiStatusRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiStatus;
use PHPUnit\Framework\TestCase;

final class GetLapiStatusQueryHandlerTest extends TestCase
{
    public function testReachable(): void
    {
        $expected = LapiStatus::reachable('v1.6.4', 'typo3-bridge', $this->fixedNow());
        $handler = new GetLapiStatusQueryHandler(
            $this->repositoryReturning($expected),
            $this->clock(),
        );

        $status = $handler->handle(new GetLapiStatusQuery());

        self::assertTrue($status->isReachable);
        self::assertSame('v1.6.4', $status->version);
        self::assertSame('typo3-bridge', $status->bouncerName);
    }

    public function testUnreachableTimeout(): void
    {
        $expected = LapiStatus::unreachable('timeout', $this->fixedNow());
        $handler = new GetLapiStatusQueryHandler(
            $this->repositoryReturning($expected),
            $this->clock(),
        );

        $status = $handler->handle(new GetLapiStatusQuery());

        self::assertFalse($status->isReachable);
        self::assertSame('timeout', $status->lastError);
    }

    public function testRepositoryReceivesClockNow(): void
    {
        $captured = new GetLapiStatusCheckedAtCapture();
        $repo = new class ($captured) implements LapiStatusRepositoryInterface {
            public function __construct(private readonly GetLapiStatusCheckedAtCapture $captured) {}

            public function findStatus(\DateTimeImmutable $checkedAt): LapiStatus
            {
                $this->captured->checkedAt = $checkedAt;

                return LapiStatus::reachable('v1.0', null, $checkedAt);
            }
        };

        new GetLapiStatusQueryHandler($repo, $this->clock())->handle(new GetLapiStatusQuery());

        self::assertNotNull($captured->checkedAt);
        self::assertSame($this->fixedNow()->getTimestamp(), $captured->checkedAt->getTimestamp());
    }

    private function repositoryReturning(LapiStatus $status): LapiStatusRepositoryInterface
    {
        return new class ($status) implements LapiStatusRepositoryInterface {
            public function __construct(private readonly LapiStatus $status) {}

            public function findStatus(\DateTimeImmutable $checkedAt): LapiStatus
            {
                return $this->status;
            }
        };
    }

    private function clock(): ClockInterface
    {
        return new class ($this->fixedNow()) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    private function fixedNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-21T10:00:00+00:00');
    }
}

/**
 * Typed capture helper — replaces the previous (object) ['checkedAt' => null]
 * pattern that PHPStan can't reason about. Test-only.
 */
final class GetLapiStatusCheckedAtCapture
{
    public ?\DateTimeImmutable $checkedAt = null;
}
