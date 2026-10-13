<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Cache;

use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Cache\CacheBackendStateTracker;
use Koh\CrowdsecBridge\Infrastructure\Cache\CachedDecisionRepository;
use Koh\CrowdsecBridge\Infrastructure\Cache\CacheKeyBuilder;
use Moselwal\KeyValueStore\KeyValueStoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CachedDecisionRepositoryTest extends TestCase
{
    public function testCacheHitSkipsInner(): void
    {
        $inner = $this->innerThatThrowsIfCalled();
        $kvs = new FakeKeyValueStore([
            CacheKeyBuilder::VERSION_KEY => 7,
        ]);
        $tracker = new CacheBackendStateTracker(new NullLogger());
        $repo = $this->repo($inner, $kvs, $tracker);

        // Seed the store with the *array* form the cache now uses (C6 refactor).
        $key = new CacheKeyBuilder()->build(7, new FilterSelection(), new Pagination(1));
        $kvs->set($key, [[
            'id' => 1,
            'scope' => 'Ip',
            'type' => 'ban',
            'value' => '203.0.113.1',
            'duration' => '1h',
            'expires_at' => '2026-05-21T12:00:00+00:00',
            'origin' => 'cscli',
            'scenario' => 'test',
        ]], 60);

        $result = $repo->findActive(new FilterSelection(), new Pagination(1));

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]->id->toInt());
        self::assertSame('203.0.113.1', $result[0]->value);
    }

    public function testCacheMissCallsInnerAndStores(): void
    {
        $expected = [$this->decision(42)];
        $inner = $this->innerReturning($expected);
        $kvs = new FakeKeyValueStore([CacheKeyBuilder::VERSION_KEY => 3]);
        $tracker = new CacheBackendStateTracker(new NullLogger());
        $repo = $this->repo($inner, $kvs, $tracker);

        $result = $repo->findActive(new FilterSelection(), new Pagination(1));

        self::assertSame($expected, $result);

        $key = new CacheKeyBuilder()->build(3, new FilterSelection(), new Pagination(1));
        $stored = $kvs->getRaw($key);
        self::assertIsArray($stored, 'Cache must be populated after miss');
        self::assertIsArray($stored[0], 'C6: cache must store array form, not Decision objects');
        self::assertSame(42, $stored[0]['id']);
        self::assertSame('Ip', $stored[0]['scope']);
        self::assertSame('1h', $stored[0]['duration']);
    }

    public function testCorruptCachedEntryDegradesToMiss(): void
    {
        $expectedFromInner = [$this->decision(7)];
        $inner = $this->innerReturning($expectedFromInner);
        $kvs = new FakeKeyValueStore([CacheKeyBuilder::VERSION_KEY => 1]);
        $repo = $this->repo($inner, $kvs, new CacheBackendStateTracker(new NullLogger()));

        // Seed cache with a payload that looks valid but has an unknown scope —
        // simulates a compromised or stale KVS entry.
        $key = new CacheKeyBuilder()->build(1, new FilterSelection(), new Pagination(1));
        $kvs->set($key, [[
            'id' => 1,
            'scope' => 'Galaxy',
            'type' => 'ban',
            'value' => 'x',
            'duration' => '1h',
            'expires_at' => null,
            'origin' => 'cscli',
            'scenario' => 'tampered',
        ]], 60);

        $result = $repo->findActive(new FilterSelection(), new Pagination(1));

        // Rehydration failed → cache treated as miss → inner repo consulted.
        self::assertSame($expectedFromInner, $result);
    }

    public function testBackendDownOnVersionReadFallsThroughToInner(): void
    {
        $expected = [$this->decision(99)];
        $inner = $this->innerReturning($expected);
        $kvs = new FakeKeyValueStore([], throwOnGet: true);
        $tracker = new CacheBackendStateTracker(new NullLogger());
        $repo = $this->repo($inner, $kvs, $tracker);

        $result = $repo->findActive(new FilterSelection(), new Pagination(1));

        self::assertSame($expected, $result);
        self::assertTrue($tracker->isBackendDown());
    }

    public function testBackendDownOnWriteStillReturnsResult(): void
    {
        $expected = [$this->decision(7)];
        $inner = $this->innerReturning($expected);
        $kvs = new FakeKeyValueStore(
            [CacheKeyBuilder::VERSION_KEY => 1],
            throwOnSet: true,
        );
        $tracker = new CacheBackendStateTracker(new NullLogger());
        $repo = $this->repo($inner, $kvs, $tracker);

        $result = $repo->findActive(new FilterSelection(), new Pagination(1));

        self::assertSame($expected, $result);
        self::assertTrue($tracker->isBackendDown());
    }

    public function testFindByIdBypassesCache(): void
    {
        $expected = $this->decision(11);
        $inner = new class ($expected) implements DecisionRepositoryInterface {
            public bool $findByIdCalled = false;

            public function __construct(private readonly Decision $expected) {}

            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                return [];
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return null;
            }

            public function findById(DecisionId $id): Decision
            {
                $this->findByIdCalled = true;

                return $this->expected;
            }
        };
        $kvs = new FakeKeyValueStore([CacheKeyBuilder::VERSION_KEY => 1]);
        $repo = $this->repo($inner, $kvs, new CacheBackendStateTracker(new NullLogger()));

        $result = $repo->findById(new DecisionId(11));

        self::assertSame($expected, $result);
        self::assertTrue($inner->findByIdCalled);
    }

    public function testFindByIpBypassesCache(): void
    {
        $expected = $this->decision(22);
        $inner = new class ($expected) implements DecisionRepositoryInterface {
            public bool $findByIpCalled = false;

            public function __construct(private readonly Decision $expected) {}

            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                return [];
            }

            public function findById(DecisionId $id): ?Decision
            {
                return null;
            }

            public function findByIp(IpAddress $ip): Decision
            {
                $this->findByIpCalled = true;

                return $this->expected;
            }
        };
        $kvs = new FakeKeyValueStore([CacheKeyBuilder::VERSION_KEY => 1]);
        $repo = $this->repo($inner, $kvs, new CacheBackendStateTracker(new NullLogger()));

        $result = $repo->findByIp(new IpAddress('203.0.113.42'));

        self::assertSame($expected, $result);
        self::assertTrue($inner->findByIpCalled);
    }

    public function testTtlClampedTooHigh(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('notice')
            ->with(self::stringContains('clamped to 300'));

        new CachedDecisionRepository(
            inner: $this->innerThatThrowsIfCalled(),
            kvs: new FakeKeyValueStore([]),
            keyBuilder: new CacheKeyBuilder(),
            tracker: new CacheBackendStateTracker(new NullLogger()),
            logger: $logger,
            ttlSeconds: 5000,
        );
    }

    public function testTtlClampedTooLow(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('notice')
            ->with(self::stringContains('clamped to 1'));

        new CachedDecisionRepository(
            inner: $this->innerThatThrowsIfCalled(),
            kvs: new FakeKeyValueStore([]),
            keyBuilder: new CacheKeyBuilder(),
            tracker: new CacheBackendStateTracker(new NullLogger()),
            logger: $logger,
            ttlSeconds: 0,
        );
    }

    public function testTtlInRangeNoNoticeLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('notice');

        new CachedDecisionRepository(
            inner: $this->innerThatThrowsIfCalled(),
            kvs: new FakeKeyValueStore([]),
            keyBuilder: new CacheKeyBuilder(),
            tracker: new CacheBackendStateTracker(new NullLogger()),
            logger: $logger,
            ttlSeconds: 30,
        );
    }

    private function repo(
        DecisionRepositoryInterface $inner,
        KeyValueStoreInterface $kvs,
        CacheBackendStateTracker $tracker,
    ): CachedDecisionRepository {
        return new CachedDecisionRepository(
            inner: $inner,
            kvs: $kvs,
            keyBuilder: new CacheKeyBuilder(),
            tracker: $tracker,
            logger: new NullLogger(),
            ttlSeconds: 10,
        );
    }

    private function decision(int $id): Decision
    {
        return new Decision(
            id: new DecisionId($id),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.' . $id,
            originalDuration: Duration::fromHours(1),
            expiresAt: new \DateTimeImmutable('2026-05-21T12:00:00+00:00'),
            origin: 'cscli',
            scenario: 'test',
        );
    }

    /**
     * @param list<Decision> $decisions
     */
    private function innerReturning(array $decisions): DecisionRepositoryInterface
    {
        return new class ($decisions) implements DecisionRepositoryInterface {
            /** @param list<Decision> $decisions */
            public function __construct(private readonly array $decisions) {}

            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                return $this->decisions;
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return null;
            }

            public function findById(DecisionId $id): ?Decision
            {
                return null;
            }
        };
    }

    private function innerThatThrowsIfCalled(): DecisionRepositoryInterface
    {
        return new class implements DecisionRepositoryInterface {
            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                throw new \RuntimeException('inner must not be called');
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return null;
            }

            public function findById(DecisionId $id): ?Decision
            {
                return null;
            }
        };
    }
}

/**
 * Minimal in-memory fake for the keyvalue-store interface — sufficient for these unit tests.
 */
final class FakeKeyValueStore implements KeyValueStoreInterface
{
    /** @var array<string, mixed> */
    private array $store;

    public function __construct(
        array $seed = [],
        private readonly bool $throwOnGet = false,
        private readonly bool $throwOnSet = false,
    ) {
        $this->store = $seed;
    }

    public function get(string $key): mixed
    {
        if ($this->throwOnGet) {
            throw new \RuntimeException('mock backend down on get');
        }

        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($this->throwOnSet) {
            throw new \RuntimeException('mock backend down on set');
        }
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }

    public function increment(string $key): int
    {
        $current = $this->store[$key] ?? 0;
        $next = (int) $current + 1;
        $this->store[$key] = $next;

        return $next;
    }

    public function getRaw(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }
}
