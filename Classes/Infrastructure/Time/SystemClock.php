<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Time;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
