<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Dto;

use Koh\CrowdsecBridge\Application\Dto\AlertListView;
use Koh\CrowdsecBridge\Application\Dto\DecisionListView;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use PHPUnit\Framework\TestCase;

/**
 * `{view.filtered}` is what the templates read; `{view.hasFilter}` resolved to
 * nothing in Fluid and the reset link never showed.
 */
final class ListViewFilteredTest extends TestCase
{
    public function testDecisionListIsFilteredExactlyWhenAFilterIsApplied(): void
    {
        self::assertFalse(new DecisionListView([], new Pagination(1), new FilterSelection())->isFiltered());
        self::assertTrue(new DecisionListView([], new Pagination(1), new FilterSelection(DecisionScope::Ip))->isFiltered());
    }

    public function testAlertListIsFilteredExactlyWhenAFilterIsApplied(): void
    {
        self::assertFalse(new AlertListView([], new Pagination(1), new AlertFilterSelection())->isFiltered());
        self::assertTrue(new AlertListView([], new Pagination(1), new AlertFilterSelection(DecisionScope::Ip))->isFiltered());
    }
}
