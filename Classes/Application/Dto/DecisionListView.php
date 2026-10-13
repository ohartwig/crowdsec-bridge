<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

final readonly class DecisionListView
{
    /**
     * @param list<DecisionView> $decisions
     */
    public function __construct(
        public array $decisions,
        public Pagination $pagination,
        public FilterSelection $appliedFilter,
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->decisions;
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
