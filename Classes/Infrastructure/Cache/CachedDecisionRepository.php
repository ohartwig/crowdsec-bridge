<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Cache;

use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Exception\InvalidDurationException;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Moselwal\KeyValueStore\KeyValueStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Decorator over LapiDecisionRepository that caches findActive() results in a KVS
 * backend with short TTL. findById() and findByIp() bypass the cache entirely (FR-004,
 * research R-005) because Quick-Check and delete-origin-snapshots need the live state.
 *
 * Only instantiated by the DI container when moselwal/keyvalue-store is installed AND
 * cacheEnabled=1. Otherwise the bare LapiDecisionRepository is used directly.
 */
final readonly class CachedDecisionRepository implements DecisionRepositoryInterface
{
    public const TTL_MIN = 1;
    public const TTL_MAX = 300;
    public const TTL_DEFAULT = 10;

    private int $effectiveTtl;

    public function __construct(
        private DecisionRepositoryInterface $inner,
        private KeyValueStoreInterface $kvs,
        private CacheKeyBuilder $keyBuilder,
        private CacheBackendStateTracker $tracker,
        private LoggerInterface $logger,
        int $ttlSeconds = self::TTL_DEFAULT,
    ) {
        $clamped = max(self::TTL_MIN, min(self::TTL_MAX, $ttlSeconds));
        if ($clamped !== $ttlSeconds) {
            $this->logger->notice(
                \sprintf(
                    'CrowdSec Bridge cacheTtl out of range: %d clamped to %d (allowed: %d-%d)',
                    $ttlSeconds,
                    $clamped,
                    self::TTL_MIN,
                    self::TTL_MAX,
                ),
            );
        }
        $this->effectiveTtl = $clamped;
    }

    /**
     * @return list<Decision>
     */
    public function findActive(FilterSelection $filter, Pagination $pagination): array
    {
        if ($this->tracker->isBackendDown()) {
            return $this->inner->findActive($filter, $pagination);
        }

        $version = $this->readVersion();
        if (null === $version) {
            // Backend errored — fall through to inner repo.
            return $this->inner->findActive($filter, $pagination);
        }

        $cacheKey = $this->keyBuilder->build($version, $filter, $pagination);

        $cached = $this->safeGet($cacheKey);
        if (null !== $cached) {
            return $cached;
        }

        $decisions = $this->inner->findActive($filter, $pagination);
        $this->safeSet($cacheKey, array_map(self::toCacheArray(...), $decisions));

        return $decisions;
    }

    public function findById(DecisionId $id): ?Decision
    {
        return $this->inner->findById($id);
    }

    public function findByIp(IpAddress $ip): ?Decision
    {
        return $this->inner->findByIp($ip);
    }

    /**
     * Reads the generation counter. null on backend errors → marks tracker, caller falls back.
     */
    private function readVersion(): ?int
    {
        try {
            $value = $this->kvs->get($this->keyBuilder->versionKey());
        } catch (\Throwable $caught) {
            $this->tracker->markBackendDown(
                'get(' . $this->keyBuilder->versionKey() . ') failed: ' . $caught->getMessage(),
            );

            return null;
        }

        if (null === $value) {
            // No version stored yet — treat as version 1. First write will increment to 2 and
            // make any v1-prefixed entries unreachable (correctness guaranteed).
            return 1;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        // Unexpected payload — treat as inconsistent state.
        $this->tracker->markBackendDown('version key contained non-integer payload');

        return null;
    }

    /**
     * @return list<Decision>|null null = miss or backend down
     */
    private function safeGet(string $cacheKey): ?array
    {
        try {
            $value = $this->kvs->get($cacheKey);
        } catch (\Throwable $caught) {
            $this->tracker->markBackendDown('get(' . $cacheKey . ') failed: ' . $caught->getMessage());

            return null;
        }
        if (!\is_array($value)) {
            return null;
        }

        $rehydrated = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                $this->logger->warning('CrowdSec Bridge cache payload contained non-array entry — treating as miss');

                return null;
            }
            try {
                $rehydrated[] = self::fromCacheArray($entry);
            } catch (\ValueError|InvalidDurationException|\InvalidArgumentException|\Exception $caught) {
                $this->logger->warning(
                    'CrowdSec Bridge cache payload could not be rehydrated — treating as miss',
                    ['reason' => $caught->getMessage()],
                );

                return null;
            }
        }

        return $rehydrated;
    }

    /**
     * @param list<array<string, scalar|null>> $serialized
     */
    private function safeSet(string $cacheKey, array $serialized): void
    {
        try {
            $this->kvs->set($cacheKey, $serialized, $this->effectiveTtl);
        } catch (\Throwable $caught) {
            $this->tracker->markBackendDown('set(' . $cacheKey . ') failed: ' . $caught->getMessage());
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function toCacheArray(Decision $decision): array
    {
        return [
            'id' => $decision->id->toInt(),
            'scope' => $decision->scope->value,
            'type' => $decision->type->value,
            'value' => $decision->value,
            'duration' => $decision->originalDuration->value,
            'expires_at' => $decision->expiresAt?->format(\DATE_ATOM),
            'origin' => $decision->origin,
            'scenario' => $decision->scenario,
        ];
    }

    /**
     * Refactor C6: cache stores plain arrays and Decisions are reconstructed via the
     * domain constructors. A compromised KVS payload can no longer inject arbitrary
     * objects — any rehydration error degrades the entry to a cache miss + warning.
     *
     * @param array<string, mixed> $entry
     */
    private static function fromCacheArray(array $entry): Decision
    {
        $id = isset($entry['id']) && is_numeric($entry['id']) ? (int) $entry['id'] : 0;
        $expiresRaw = $entry['expires_at'] ?? null;

        return new Decision(
            id: new DecisionId($id),
            scope: DecisionScope::fromLapi((string) ($entry['scope'] ?? '')),
            type: DecisionType::fromLapi((string) ($entry['type'] ?? '')),
            value: (string) ($entry['value'] ?? ''),
            originalDuration: new Duration((string) ($entry['duration'] ?? '')),
            expiresAt: \is_string($expiresRaw) && '' !== $expiresRaw ? new \DateTimeImmutable($expiresRaw) : null,
            origin: (string) ($entry['origin'] ?? ''),
            scenario: (string) ($entry['scenario'] ?? ''),
        );
    }
}
