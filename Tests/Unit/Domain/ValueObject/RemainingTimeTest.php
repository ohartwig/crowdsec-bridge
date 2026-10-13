<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\RemainingTime;
use PHPUnit\Framework\TestCase;

final class RemainingTimeTest extends TestCase
{
    public function testDaysHoursMinutesBreakdown(): void
    {
        $clock = $this->fixedClock('2026-05-20T12:00:00+00:00');
        $decision = $this->decisionExpiringAt('2026-05-22T14:35:00+00:00');

        $remaining = RemainingTime::fromDecision($decision, $clock);

        self::assertFalse($remaining->isExpired);
        self::assertSame(2, $remaining->days);
        self::assertSame(2, $remaining->hours);
        self::assertSame(35, $remaining->minutes);
        self::assertSame('remaining_time.days_hours_minutes', $remaining->toLocallangKey());
    }

    public function testHoursAndMinutesOnly(): void
    {
        $clock = $this->fixedClock('2026-05-20T12:00:00+00:00');
        $decision = $this->decisionExpiringAt('2026-05-20T18:22:00+00:00');

        $remaining = RemainingTime::fromDecision($decision, $clock);

        self::assertSame(0, $remaining->days);
        self::assertSame(6, $remaining->hours);
        self::assertSame(22, $remaining->minutes);
        self::assertSame('remaining_time.hours_minutes', $remaining->toLocallangKey());
    }

    public function testMinutesOnly(): void
    {
        $clock = $this->fixedClock('2026-05-20T12:00:00+00:00');
        $decision = $this->decisionExpiringAt('2026-05-20T12:42:00+00:00');

        $remaining = RemainingTime::fromDecision($decision, $clock);

        self::assertSame('remaining_time.minutes', $remaining->toLocallangKey());
        self::assertSame(42, $remaining->minutes);
    }

    public function testExpired(): void
    {
        $clock = $this->fixedClock('2026-05-20T12:00:00+00:00');
        $decision = $this->decisionExpiringAt('2026-05-19T12:00:00+00:00');

        $remaining = RemainingTime::fromDecision($decision, $clock);

        self::assertTrue($remaining->isExpired);
        self::assertSame('remaining_time.expired', $remaining->toLocallangKey());
    }

    public function testFallbackToOriginalDurationWhenExpiresAtMissing(): void
    {
        $clock = $this->fixedClock('2026-05-20T12:00:00+00:00');
        $decision = new Decision(
            id: new DecisionId(1),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.42',
            originalDuration: Duration::fromHours(24),
            expiresAt: null,
            origin: 'cscli',
            scenario: 'manual',
        );

        $remaining = RemainingTime::fromDecision($decision, $clock);

        self::assertSame(1, $remaining->days);
        self::assertSame(0, $remaining->hours);
        self::assertSame(0, $remaining->minutes);
    }

    private function fixedClock(string $iso): ClockInterface
    {
        return new class ($iso) implements ClockInterface {
            public function __construct(private readonly string $iso) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->iso);
            }
        };
    }

    private function decisionExpiringAt(string $iso): Decision
    {
        return new Decision(
            id: new DecisionId(1),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.42',
            originalDuration: Duration::fromHours(1),
            expiresAt: new \DateTimeImmutable($iso),
            origin: 'cscli',
            scenario: 'test',
        );
    }

    /**
     * Fluid reads getters, it calls no method with parentheses.
     */
    public function testFluidReadsTheKeyAndTheArgumentsThroughGetters(): void
    {
        $remaining = RemainingTime::fromDecision(
            $this->decisionExpiringAt('2026-05-22T14:35:00+00:00'),
            $this->fixedClock('2026-05-20T12:00:00+00:00'),
        );

        self::assertSame($remaining->toLocallangKey(), $remaining->getLocallangKey());
        self::assertSame($remaining->templateArguments(), $remaining->getTemplateArguments());
    }
}
