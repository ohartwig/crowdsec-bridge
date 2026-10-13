<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class Pagination
{
    public const PAGE_SIZE = 50;

    public function __construct(public int $currentPage)
    {
        if ($currentPage < 1) {
            throw new \InvalidArgumentException(\sprintf('currentPage must be >= 1, got %d', $currentPage));
        }
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
