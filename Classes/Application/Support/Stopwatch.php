<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Support;

/**
 * Simple monotonic-clock stopwatch for audit-latency measurement.
 *
 * `hrtime(true)` is monotonic and immune to wall-clock jumps (NTP, DST). Tests
 * that fake time should fake a derivative of this class rather than monkey-
 * patching `hrtime`. Refactor C1: extracted from {Add,Delete}DecisionCommand-
 * Handler and CheckIpQueryHandler where the same rounded-int millisecond
 * stopwatch was duplicated.
 */
final class Stopwatch
{
    private int $startedAtNs;

    private function __construct(int $startedAtNs)
    {
        $this->startedAtNs = $startedAtNs;
    }

    public static function start(): self
    {
        return new self(hrtime(true));
    }

    public function elapsedMs(): int
    {
        return (int) round((hrtime(true) - $this->startedAtNs) / 1_000_000);
    }
}
