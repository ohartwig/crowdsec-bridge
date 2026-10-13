<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Cache;

use Koh\CrowdsecBridge\Infrastructure\Cache\CacheBackendStateTracker;
use Koh\CrowdsecBridge\Infrastructure\Cache\CacheKeyBuilder;
use Koh\CrowdsecBridge\Infrastructure\Cache\KeyValueStoreDecisionCacheInvalidator;
use Moselwal\KeyValueStore\KeyValueStoreInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Invalidation is what makes a newly written ban take effect. Two failure modes
 * matter and they pull in opposite directions.
 *
 * If the key-value backend is down, every request would otherwise pay for a
 * connection attempt that cannot succeed — hence the circuit breaker. But a
 * failure that is swallowed without being recorded is worse than the cost: the
 * version key never advances, and every visitor keeps being served against a
 * decision list that no longer reflects reality.
 *
 * The real key builder and state tracker are used; both are final and cheap to
 * construct, and stubbing them would hide the key that actually gets touched.
 */
final class KeyValueStoreDecisionCacheInvalidatorTest extends TestCase
{
    private CacheKeyBuilder $keys;
    private CacheBackendStateTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = new CacheKeyBuilder();
        $this->tracker = new CacheBackendStateTracker(new NullLogger());
    }

    private function invalidator(KeyValueStoreInterface $store): KeyValueStoreDecisionCacheInvalidator
    {
        return new KeyValueStoreDecisionCacheInvalidator($store, $this->keys, $this->tracker);
    }

    #[Test]
    public function invalidationAdvancesTheVersionKey(): void
    {
        $incremented = [];

        $store = $this->createMock(KeyValueStoreInterface::class);
        $store->method('increment')->willReturnCallback(
            static function (string $key) use (&$incremented): int {
                $incremented[] = $key;

                return 2;
            },
        );

        $this->invalidator($store)->invalidate();

        self::assertSame([$this->keys->versionKey()], $incremented);
    }

    /**
     * The circuit breaker. Once the backend is known to be down, further calls
     * would each pay a connection timeout for nothing.
     */
    #[Test]
    public function aBackendAlreadyKnownToBeDownIsNotContactedAgain(): void
    {
        $this->tracker->markBackendDown('an earlier failure');

        $store = $this->createMock(KeyValueStoreInterface::class);
        $store->expects(self::never())->method('increment');

        $this->invalidator($store)->invalidate();
    }

    /**
     * The regression worth guarding: a failure has to be recorded, not
     * swallowed. Without it the breaker never opens, and — worse — nothing
     * anywhere shows that bans have stopped taking effect.
     */
    #[Test]
    public function aFailingBackendIsMarkedDown(): void
    {
        $store = $this->createMock(KeyValueStoreInterface::class);
        $store->method('increment')->willThrowException(new \RuntimeException('connection refused'));

        self::assertFalse($this->tracker->isBackendDown());

        $this->invalidator($store)->invalidate();

        self::assertTrue($this->tracker->isBackendDown());
    }

    /**
     * Invalidation is a side effect of writing a decision; letting the exception
     * escape would fail the write itself, after the ban was already created in
     * LAPI.
     */
    #[Test]
    public function aFailingBackendDoesNotFailTheCaller(): void
    {
        $store = $this->createMock(KeyValueStoreInterface::class);
        $store->method('increment')->willThrowException(new \RuntimeException('connection refused'));

        $this->invalidator($store)->invalidate();

        self::assertTrue($this->tracker->isBackendDown(), 'the failure is recorded rather than raised');
    }

    /**
     * After a failure the breaker is open, so a second attempt must not reach
     * the backend either.
     */
    #[Test]
    public function theBreakerStaysOpenAfterAFailure(): void
    {
        $calls = 0;

        $store = $this->createMock(KeyValueStoreInterface::class);
        $store->method('increment')->willReturnCallback(
            static function () use (&$calls): int {
                ++$calls;

                throw new \RuntimeException('connection refused');
            },
        );

        $invalidator = $this->invalidator($store);
        $invalidator->invalidate();
        $invalidator->invalidate();

        self::assertSame(1, $calls, 'the second call is short-circuited');
    }
}
