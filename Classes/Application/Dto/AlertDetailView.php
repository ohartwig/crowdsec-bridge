<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertLabel;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;

final readonly class AlertDetailView
{
    /**
     * @param list<DecisionView> $decisions (max. 50)
     * @param list<AlertLabel>   $labels
     */
    public function __construct(
        public int $id,
        public string $scenario,
        public \DateTimeImmutable $createdAt,
        public AlertSource $source,
        public array $labels,
        public array $decisions,
        public int $decisionsOverflow,
    ) {}
}
