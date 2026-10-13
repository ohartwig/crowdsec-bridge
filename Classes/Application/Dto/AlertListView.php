<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

final readonly class AlertListView
{
    /**
     * @param list<AlertView> $alerts
     */
    public function __construct(
        public array $alerts,
        public Pagination $pagination,
        public AlertFilterSelection $appliedFilter,
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->alerts;
    }

    public function hasFilter(): bool
    {
        return !$this->appliedFilter->isEmpty();
    }

    /**
     * What the template reads: `{view.hasFilter}` resolves to nothing in
     * Fluid (it looks for getHasFilter, isHasFilter, hasHasFilter), and the
     * reset link never showed.
     */
    public function isFiltered(): bool
    {
        return $this->hasFilter();
    }
}
