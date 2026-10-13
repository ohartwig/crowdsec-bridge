<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use PHPUnit\Framework\TestCase;

final class FilterSelectionTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $f = new FilterSelection();
        self::assertTrue($f->isEmpty());
        self::assertSame([], $f->toLapiQueryParams());
    }

    public function testScopeOnlyIsNotEmpty(): void
    {
        $f = new FilterSelection(scope: DecisionScope::Range);
        self::assertFalse($f->isEmpty());
        self::assertSame(['scope' => 'Range'], $f->toLapiQueryParams());
    }

    /**
     * The origin parameter is called `origins`, plural.
     *
     * This used to assert `origin`, and it was green because the mock on the
     * other side was written by the same hand. A real CrowdSec answers 200 to
     * `origin=` and ignores it: no error, no filter, and an operator looking at
     * an unfiltered list under a filtered heading. The name is the assertion.
     */
    public function testTheOriginFilterIsSpelledOriginsPlural(): void
    {
        $f = new FilterSelection(
            scope: DecisionScope::Range,
            type: DecisionType::Ban,
            origin: 'typo3',
        );
        self::assertSame([
            'scope' => 'Range',
            'type' => 'ban',
            'origins' => 'typo3',
        ], $f->toLapiQueryParams());
    }

    /**
     * CrowdSec normalises the autonomous-system scope to `AS` and stores it that
     * way, so a filter that asked for `As` was asking about a spelling that is
     * never on the wire.
     */
    public function testTheAutonomousSystemScopeIsSentTheWayCrowdsecStoresIt(): void
    {
        self::assertSame(
            ['scope' => 'AS'],
            new FilterSelection(scope: DecisionScope::As)->toLapiQueryParams(),
        );
    }

    public function testEmptyStringOriginIsTreatedAsAbsent(): void
    {
        $f = new FilterSelection(origin: '');
        self::assertTrue($f->isEmpty());
        self::assertSame([], $f->toLapiQueryParams());
    }
}
