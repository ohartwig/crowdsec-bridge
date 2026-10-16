<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class Pagination
{
    public const PAGE_SIZE = 50;

    /**
     * How deep a list may page when the server offers no offset and the page
     * is cut out of one answer: the LAPI's alert endpoint understands `limit`
     * only, so page N costs N pages of transfer and parsing. Without a ceiling
     * a page number was a lever on the central LAPI (AUD-002-F02). Fifty
     * pages of fifty is a week of alerts at CrowdSec's default flush.
     */
    public const MAX_SNAPSHOT_PAGE = 50;
    public const MAX_SNAPSHOT_ITEMS = self::MAX_SNAPSHOT_PAGE * self::PAGE_SIZE;

    public function __construct(public int $currentPage)
    {
        if ($currentPage < 1) {
            throw new \InvalidArgumentException(\sprintf('currentPage must be >= 1, got %d', $currentPage));
        }
    }

    /**
     * The page for a snapshot-paged list: below one becomes one, beyond the
     * ceiling becomes the ceiling. Never throws, because the number comes from
     * the query string.
     */
    public static function forSnapshot(int $requestedPage): self
    {
        return new self(min(max(1, $requestedPage), self::MAX_SNAPSHOT_PAGE));
    }

    public function isSnapshotCeiling(): bool
    {
        return $this->currentPage >= self::MAX_SNAPSHOT_PAGE;
    }

    /**
     * Like hasNextPage(), but there is no page past the ceiling to link to.
     */
    public function hasNextSnapshotPage(int $itemsOnCurrentPage): bool
    {
        return $this->hasNextPage($itemsOnCurrentPage) && !$this->isSnapshotCeiling();
    }

    public function offset(): int
    {
        return ($this->currentPage - 1) * self::PAGE_SIZE;
    }

    public function pageSize(): int
    {
        return self::PAGE_SIZE;
    }

    public function hasNextPage(int $itemsOnCurrentPage): bool
    {
        return $itemsOnCurrentPage >= self::PAGE_SIZE;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function previousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function nextPage(): int
    {
        return $this->currentPage + 1;
    }

    /**
     * Fluid resolves `pagination.nextPage` through get*, is*, has* and only
     * then the property - never the method of that name. It reached
     * hasNextPage(), which needs an argument: the decision list answered 500
     * as soon as it had a second page (2026-10-03). `previousPage` reached
     * hasPreviousPage() and linked to page "1" as a boolean.
     */
    public function getNextPage(): int
    {
        return $this->nextPage();
    }

    public function getPreviousPage(): int
    {
        return $this->previousPage();
    }

    public function isFirstPage(): bool
    {
        return !$this->hasPreviousPage();
    }
}
