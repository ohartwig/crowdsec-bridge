<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\RemainingTime;

final readonly class DecisionView
{
    public function __construct(
        public int $id,
        public string $scope,
        public string $type,
        public string $value,
        public RemainingTime $remainingTime,
        public string $origin,
        public string $scenario,
    ) {}

    /**
     * Refactor C4: the same Decision → DecisionView projection was duplicated
     * in ListDecisionsQueryHandler and GetAlertDetailQueryHandler. Centralised
     * here so a future field addition (e.g. labels on Decision) only touches
     * this file.
     */
    public static function fromDomain(Decision $decision, ClockInterface $clock): self
    {
        return new self(
            id: $decision->id->toInt(),
            scope: $decision->scope->value,
            type: $decision->type->value,
            value: $decision->value,
            remainingTime: RemainingTime::fromDecision($decision, $clock),
            origin: $decision->origin,
            scenario: $decision->scenario,
        );
    }
}
