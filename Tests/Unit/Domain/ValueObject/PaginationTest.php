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
}
