<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
