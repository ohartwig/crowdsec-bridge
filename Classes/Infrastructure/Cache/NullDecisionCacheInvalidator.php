<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Cache;

use Koh\CrowdsecBridge\Domain\Contract\DecisionCacheInvalidatorInterface;

/**
 * Default no-op invalidator. Bound when moselwal/keyvalue-store is not installed
 * or when cacheEnabled=0. Constitution null-adapter pattern.
 */
final readonly class NullDecisionCacheInvalidator implements DecisionCacheInvalidatorInterface
{
    public function invalidate(): void
    {
        // intentional no-op
    }
}
