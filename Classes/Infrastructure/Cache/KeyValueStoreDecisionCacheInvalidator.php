<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Cache;

use Koh\CrowdsecBridge\Domain\Contract\DecisionCacheInvalidatorInterface;
use Moselwal\KeyValueStore\KeyValueStoreInterface;

/**
 * Atomically invalidates the decision-list cache by incrementing the
 * generation counter (Clarify Q1 / research R-002). O(1) operation,
 * KVS-backend-agnostic.
 *
 * Only loaded by the DI container when moselwal/keyvalue-store is installed
 * AND cacheEnabled=1 (see Services.yaml conditional binding). Therefore the
 * KeyValueStoreInterface type hint is safe — PHP never tries to autoload
 * the missing interface in the fallback configuration.
 */
final readonly class KeyValueStoreDecisionCacheInvalidator implements DecisionCacheInvalidatorInterface
{
    public function __construct(
        private KeyValueStoreInterface $kvs,
        private CacheKeyBuilder $keyBuilder,
        private CacheBackendStateTracker $tracker,
    ) {}

    public function invalidate(): void
    {
        if ($this->tracker->isBackendDown()) {
            return;
        }

        try {
            $this->kvs->increment($this->keyBuilder->versionKey());
        } catch (\Throwable $caught) {
            $this->tracker->markBackendDown(
                'increment(' . $this->keyBuilder->versionKey() . ') failed: ' . $caught->getMessage(),
            );
        }
    }
}
