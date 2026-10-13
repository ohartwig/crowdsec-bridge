<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Command;

use Koh\CrowdsecBridge\Domain\Contract\DecisionCacheInvalidatorInterface;

final class SpyCacheInvalidator implements DecisionCacheInvalidatorInterface
{
    public int $callCount = 0;

    public function invalidate(): void
    {
        ++$this->callCount;
    }
}
