<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use PHPUnit\Framework\TestCase;

final class AlertSourceTest extends TestCase
{
    public function testKnownScopeMapped(): void
    {
        $source = new AlertSource(
            scopeRaw: 'Ip',
            value: '1.2.3.4',
            scope: DecisionScope::Ip,
            ip: '1.2.3.4',
            country: 'DE',
        );

        self::assertSame(DecisionScope::Ip, $source->scope);
        self::assertSame('Ip', $source->scopeRaw);
        self::assertSame('DE', $source->country);
        self::assertFalse($source->hasGeo());
    }

    public function testUnknownScopeRetainsRaw(): void
    {
        $source = new AlertSource(
            scopeRaw: 'Galaxy',
            value: 'unknown-thing',
            scope: null,
        );

        self::assertNull($source->scope);
        self::assertSame('Galaxy', $source->scopeRaw);
    }

    public function testHasGeoOnlyWhenBothCoordinatesSet(): void
    {
        $withGeo = new AlertSource(scopeRaw: 'Ip', value: '1.2.3.4', latitude: 52.5, longitude: 13.4);
        self::assertTrue($withGeo->hasGeo());

        $halfGeo = new AlertSource(scopeRaw: 'Ip', value: '1.2.3.4', latitude: 52.5);
        self::assertFalse($halfGeo->hasGeo());
    }
}
