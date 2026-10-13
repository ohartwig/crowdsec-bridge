<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class Alert
{
    /**
     * @param list<Decision>   $decisions empty in list mode (memory optimisation R-010), full in detail mode
     * @param list<AlertLabel> $labels
     */
    public function __construct(
        public AlertId $id,
        public string $scenario,
        public \DateTimeImmutable $createdAt,
        public AlertSource $source,
        public int $decisionCount,
        public array $decisions = [],
        public array $labels = [],
    ) {}
}
