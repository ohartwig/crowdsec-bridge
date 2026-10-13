<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDurationException;

/**
 * Wrapper around a CrowdSec Go-duration string (e.g. "24h", "7d", "1h30m0s").
 */
final readonly class Duration
{
    private const PATTERN = '/^(?:\d+(?:ns|us|µs|ms|s|m|h|d|w))+$/';

    /**
     * Component multipliers in seconds. "d" = 86400, "w" = 604800.
     */
    private const UNIT_SECONDS = [
        'ns' => 0,
        'us' => 0,
        'µs' => 0,
        'ms' => 0,
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
    ];

    public function __construct(public string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidDurationException::invalidFormat($value);
        }
        // Refactor C7: reject zero/sub-second durations. "0h" or "100ns" technically
        // parses but creates a meaningless decision that LAPI would expire immediately.
        if ($this->toSeconds() <= 0) {
            throw InvalidDurationException::nonPositive($value);
        }
    }

    public static function fromHours(int $hours): self
    {
        return new self($hours . 'h');
    }

    public static function fromDays(int $days): self
    {
        return new self($days . 'd');
    }

    public function toSeconds(): int
    {
        $total = 0;
        preg_match_all('/(\d+)(ns|us|µs|ms|s|m|h|d|w)/', $this->value, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $amount = (int) $match[1];
            $unit = $match[2];
            $total += $amount * self::UNIT_SECONDS[$unit];
        }

        return $total;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
