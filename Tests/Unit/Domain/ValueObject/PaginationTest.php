<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testPageOneHasOffsetZero(): void
    {
        $p = new Pagination(1);
        self::assertSame(0, $p->offset());
        self::assertFalse($p->hasPreviousPage());
    }

    public function testPageTwoHasOffsetFifty(): void
    {
        $p = new Pagination(2);
        self::assertSame(50, $p->offset());
        self::assertTrue($p->hasPreviousPage());
        self::assertSame(1, $p->previousPage());
    }

    public function testHasNextPageTrueWhenFullPage(): void
    {
        $p = new Pagination(1);
        self::assertTrue($p->hasNextPage(50));
    }

    public function testHasNextPageFalseWhenPartialPage(): void
    {
        $p = new Pagination(2);
        self::assertFalse($p->hasNextPage(23));
    }

    public function testInvalidPageRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pagination(0);
    }

    /**
     * What Fluid reads: get* and is*, never nextPage() by its own name.
     */
    public function testFluidGettersOnPageTwo(): void
    {
        $pagination = new Pagination(2);

        self::assertSame(3, $pagination->getNextPage());
        self::assertSame(1, $pagination->getPreviousPage());
        self::assertFalse($pagination->isFirstPage());
        self::assertTrue(new Pagination(1)->isFirstPage());
    }

    /**
     * The alert list pages by snapshot: page N costs N pages from the LAPI.
     * A page number from the query string therefore meets a ceiling here
     * instead of reaching the LAPI (AUD-002-F02).
     */
    public function testSnapshotPagingClampsAtTheCeiling(): void
    {
        self::assertSame(1, Pagination::forSnapshot(0)->currentPage);
        self::assertSame(1, Pagination::forSnapshot(-7)->currentPage);
        self::assertSame(7, Pagination::forSnapshot(7)->currentPage);
        self::assertSame(Pagination::MAX_SNAPSHOT_PAGE, Pagination::forSnapshot(Pagination::MAX_SNAPSHOT_PAGE)->currentPage);
        self::assertSame(Pagination::MAX_SNAPSHOT_PAGE, Pagination::forSnapshot(100000)->currentPage);
        self::assertSame(Pagination::MAX_SNAPSHOT_PAGE, Pagination::forSnapshot(\PHP_INT_MAX)->currentPage);
    }

    public function testTheCeilingPageStartsAtTheLastFiftyOfTheCeilingItems(): void
    {
        $last = Pagination::forSnapshot(Pagination::MAX_SNAPSHOT_PAGE);
        self::assertSame(Pagination::MAX_SNAPSHOT_ITEMS - Pagination::PAGE_SIZE, $last->offset());
        self::assertSame(Pagination::MAX_SNAPSHOT_ITEMS, $last->offset() + $last->pageSize());
    }

    public function testThereIsNoNextPagePastTheCeiling(): void
    {
        $last = Pagination::forSnapshot(Pagination::MAX_SNAPSHOT_PAGE);
        self::assertTrue($last->isSnapshotCeiling());
        self::assertTrue($last->hasNextPage(50), 'the generic rule still says a full page has a next one');
        self::assertFalse($last->hasNextSnapshotPage(50), 'but a snapshot list has nothing past the ceiling to link to');

        $belowIt = Pagination::forSnapshot(Pagination::MAX_SNAPSHOT_PAGE - 1);
        self::assertFalse($belowIt->isSnapshotCeiling());
        self::assertTrue($belowIt->hasNextSnapshotPage(50));
        self::assertFalse($belowIt->hasNextSnapshotPage(12));
    }
}
