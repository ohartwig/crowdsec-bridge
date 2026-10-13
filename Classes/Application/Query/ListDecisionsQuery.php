<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;

final readonly class ListDecisionsQuery
{
    public function __construct(
        public ?DecisionScope $scope = null,
        public ?DecisionType $type = null,
        public ?string $origin = null,
        public int $page = 1,
    ) {}
}
