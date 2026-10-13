<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDecisionException;

final readonly class Decision
{
    public function __construct(
        public DecisionId $id,
        public DecisionScope $scope,
        public DecisionType $type,
        public string $value,
        public Duration $originalDuration,
        public ?\DateTimeImmutable $expiresAt,
        public string $origin,
        public string $scenario,
    ) {
        if ('' === $value) {
            throw InvalidDecisionException::emptyValue();
        }
        if ('' === $origin) {
            throw InvalidDecisionException::emptyOrigin();
        }
    }
}
