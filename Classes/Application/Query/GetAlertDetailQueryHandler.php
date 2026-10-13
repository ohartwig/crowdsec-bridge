<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Application\Dto\AlertDetailView;
use Koh\CrowdsecBridge\Application\Dto\DecisionView;
use Koh\CrowdsecBridge\Domain\Contract\AlertRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Exception\AlertNotFoundException;

final readonly class GetAlertDetailQueryHandler
{
    public const MAX_DECISIONS_SHOWN = 50;

    public function __construct(
        private AlertRepositoryInterface $repository,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws AlertNotFoundException
     */
    public function handle(GetAlertDetailQuery $query): AlertDetailView
    {
        $alert = $this->repository->findAlertById($query->alertId);
        if (null === $alert) {
            throw AlertNotFoundException::forId($query->alertId);
        }

        $totalDecisions = \count($alert->decisions);
        $decisionsSlice = \array_slice($alert->decisions, 0, self::MAX_DECISIONS_SHOWN);

        $decisionViews = [];
        foreach ($decisionsSlice as $decision) {
            $decisionViews[] = DecisionView::fromDomain($decision, $this->clock);
        }

        $overflow = max(0, $totalDecisions - self::MAX_DECISIONS_SHOWN);

        return new AlertDetailView(
            id: $alert->id->toInt(),
            scenario: $alert->scenario,
            createdAt: $alert->createdAt,
            source: $alert->source,
            labels: $alert->labels,
            decisions: $decisionViews,
            decisionsOverflow: $overflow,
        );
    }
}
