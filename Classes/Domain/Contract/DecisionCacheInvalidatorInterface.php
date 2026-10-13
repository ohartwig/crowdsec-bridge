<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

/**
 * Invalidates the decision-list cache after a successful write operation.
 *
 * Implementations:
 *  - KeyValueStoreDecisionCacheInvalidator: atomically increments the generation
 *    counter so that all previously cached keys become unreachable (Clarify Q1).
 *  - NullDecisionCacheInvalidator: no-op fallback when moselwal/keyvalue-store
 *    is not installed.
 *
 * Calling contract (Feature 002 handlers):
 *  - Call AFTER the audit log has been written successfully.
 *  - Call ONLY on status "success" (Add) or "success"/"not_found" (Delete).
 *  - Implementations MUST NOT throw — a cache failure must never block the
 *    user action. Internal errors are logged via the request-scoped tracker.
 */
interface DecisionCacheInvalidatorInterface
{
    public function invalidate(): void;
}
