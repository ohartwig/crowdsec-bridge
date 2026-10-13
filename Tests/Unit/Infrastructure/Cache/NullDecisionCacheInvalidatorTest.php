<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Cache;

use Koh\CrowdsecBridge\Infrastructure\Cache\NullDecisionCacheInvalidator;
use PHPUnit\Framework\TestCase;

final class NullDecisionCacheInvalidatorTest extends TestCase
{
    public function testInvalidateIsNoOp(): void
    {
        $invalidator = new NullDecisionCacheInvalidator();
        $invalidator->invalidate();
        $invalidator->invalidate();
        $invalidator->invalidate();

        self::expectNotToPerformAssertions();
    }
}
