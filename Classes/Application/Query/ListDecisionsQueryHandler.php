<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Application\Dto\DecisionListView;
use Koh\CrowdsecBridge\Application\Dto\DecisionView;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

final readonly class ListDecisionsQueryHandler
{
    public function __construct(
        private DecisionRepositoryInterface $repository,
        private ClockInterface $clock,
    ) {}

    public function handle(ListDecisionsQuery $query): DecisionListView
    {
        $filter = new FilterSelection($query->scope, $query->type, $query->origin);
        $pagination = new Pagination(max(1, $query->page));

        $decisions = $this->repository->findActive($filter, $pagination);

        $views = [];
        foreach ($decisions as $decision) {
            $views[] = DecisionView::fromDomain($decision, $this->clock);
        }

        return new DecisionListView($views, $pagination, $filter);
    }
}
