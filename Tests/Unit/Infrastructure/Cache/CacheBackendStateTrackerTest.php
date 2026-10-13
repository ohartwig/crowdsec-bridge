<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Cache;

use Koh\CrowdsecBridge\Infrastructure\Cache\CacheBackendStateTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CacheBackendStateTrackerTest extends TestCase
{
    public function testStartsHealthy(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $tracker = new CacheBackendStateTracker($logger);
        self::assertFalse($tracker->isBackendDown());
    }

    public function testFirstMarkBackendDownLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('cache backend unavailable'),
                self::callback(static fn(array $ctx) => 'redis-down' === $ctx['reason']),
            );

        $tracker = new CacheBackendStateTracker($logger);
        $tracker->markBackendDown('redis-down');

        self::assertTrue($tracker->isBackendDown());
    }

    public function testSubsequentMarkDownDoesNotLogAgain(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $tracker = new CacheBackendStateTracker($logger);
        $tracker->markBackendDown('first');
        $tracker->markBackendDown('second');
        $tracker->markBackendDown('third');

        self::assertTrue($tracker->isBackendDown());
    }
}
