<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;

/**
 * Decomposed "time left until expiry" with whole-minute granularity.
 * Format selection happens via toLocallangKey(); the Fluid template owns localisation.
 */
final readonly class RemainingTime
{
    public function __construct(
        public int $days,
        public int $hours,
        public int $minutes,
        public bool $isExpired,
    ) {}

    public static function fromDecision(Decision $decision, ClockInterface $clock): self
    {
        $now = $clock->now();
        $expiresAt = $decision->expiresAt;

        if (null === $expiresAt) {
            $secondsLeft = $decision->originalDuration->toSeconds();
        } else {
            $secondsLeft = $expiresAt->getTimestamp() - $now->getTimestamp();
        }

        if ($secondsLeft <= 0) {
            return new self(0, 0, 0, true);
        }

        $days = intdiv($secondsLeft, 86400);
        $secondsLeft -= $days * 86400;
        $hours = intdiv($secondsLeft, 3600);
        $secondsLeft -= $hours * 3600;
        $minutes = intdiv($secondsLeft, 60);

        return new self($days, $hours, $minutes, false);
    }

    public function toLocallangKey(): string
    {
        if ($this->isExpired) {
            return 'remaining_time.expired';
        }
        if ($this->days > 0) {
            return 'remaining_time.days_hours_minutes';
        }
        if ($this->hours > 0) {
            return 'remaining_time.hours_minutes';
        }

        return 'remaining_time.minutes';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public function templateArguments(): array
    {
        return [$this->days, $this->hours, $this->minutes];
    }

    /**
     * Fluid calls no method with parentheses: `{remaining.toLocallangKey()}`
     * failed to render (2026-10-03). It reads `{remaining.locallangKey}`
     * through this getter.
     */
    public function getLocallangKey(): string
    {
        return $this->toLocallangKey();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public function getTemplateArguments(): array
    {
        return $this->templateArguments();
    }
}
