<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Application\Dto\AlertListView;
use Koh\CrowdsecBridge\Application\Dto\AlertView;
use Koh\CrowdsecBridge\Domain\Contract\AlertRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

final readonly class ListAlertsQueryHandler
{
    public function __construct(private AlertRepositoryInterface $repository) {}

    public function handle(ListAlertsQuery $query): AlertListView
    {
        $filter = new AlertFilterSelection($query->scope, $query->scenarioContains);
        // The alert endpoint pages by snapshot (see LapiAlertRepository), so
        // the page number is clamped to the ceiling rather than passed through;
        // the view says when that happened so the screen can say so too.
        $pagination = Pagination::forSnapshot($query->page);
        $pageWasClamped = $query->page > $pagination->currentPage;

        $alerts = $this->repository->findAlerts($filter, $pagination);

        if (!$filter->isEmpty() && null !== $filter->scenarioContains && '' !== $filter->scenarioContains) {
            $alerts = array_values(array_filter(
                $alerts,
                static fn(Alert $a) => $filter->matchesScenario($a->scenario),
            ));
        }

        $views = [];
        foreach ($alerts as $alert) {
            $views[] = AlertView::fromAlert($alert);
        }

        return new AlertListView($views, $pagination, $filter, $pageWasClamped);
    }
}
