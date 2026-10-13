<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;

final readonly class ListAlertsQuery
{
    public function __construct(
        public ?DecisionScope $scope = null,
        public ?string $scenarioContains = null,
        public int $page = 1,
    ) {}
}
