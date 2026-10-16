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
        /** The requested page lay beyond Pagination::MAX_SNAPSHOT_PAGE and this is the ceiling instead. */
        public bool $pageWasClamped = false,
    ) {}

    /**
     * Whether a "next" link makes sense: a full page, and not at the ceiling.
     * Read by Fluid as `{alertListView.more}`.
     */
    public function hasMore(): bool
    {
        return $this->pagination->hasNextSnapshotPage(\count($this->alerts));
    }

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
