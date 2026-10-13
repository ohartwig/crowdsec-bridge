<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;

/**
 * Outcome of a decision that was successfully created.
 *
 * Separate from WriteResult because the two outcomes are not the same shape.
 * A delete can end in "already gone", which is a success the operator should be
 * told about; an add either created the alert carrying the decision or threw.
 * And what LAPI returns for an add is an alert id, not a decision id — sharing
 * one nullable field between the two would have made every reader check which
 * kind of id they were holding.
 */
final readonly class AddDecisionResult
{
    public function __construct(public AlertId $alertId) {}
}
