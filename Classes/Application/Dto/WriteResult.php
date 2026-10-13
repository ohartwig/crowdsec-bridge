<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;

/**
 * Outcome of a delete that completed without throwing. The "failed" case is
 * signalled by exception (LapiUnavailable/Authentication) — write handlers never
 * return a failure status, so WriteResult only carries success or not-found
 * semantics. Refactor C8 removed the unused failure() factory and the
 * errorMessage carry-over field.
 *
 * The add path has its own result type (AddDecisionResult): LAPI answers a
 * creation with the id of the alert carrying the decision, and there is no
 * "already there" outcome to report.
 */
final readonly class WriteResult
{
    /**
     * @param 'success'|'not_found' $status
     */
    public function __construct(
        public string $status,
        public DecisionId $decisionId,
    ) {}

    public static function ofDelete(bool $deleted, DecisionId $id): self
    {
        return new self($deleted ? 'success' : 'not_found', $id);
    }

    public function isSuccess(): bool
    {
        return 'success' === $this->status;
    }

    public function isNotFound(): bool
    {
        return 'not_found' === $this->status;
    }
}
