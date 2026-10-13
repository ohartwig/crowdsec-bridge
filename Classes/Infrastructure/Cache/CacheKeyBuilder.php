<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Cache;

use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

/**
 * Builds deterministic cache keys for decision-list requests.
 *
 * Key format: crowdsec_bridge:decisions:v{N}:{md5(filterJson)}:p{page}
 *
 * The leading version segment is the heart of the generation-counter pattern
 * (Clarify Q1, research R-002): when the counter is incremented, all existing
 * keys become unreachable in O(1) and expire via TTL.
 */
final readonly class CacheKeyBuilder
{
    public const VERSION_KEY = 'crowdsec_bridge:decisions:version';
    private const KEY_PREFIX = 'crowdsec_bridge:decisions';

    public function versionKey(): string
    {
        return self::VERSION_KEY;
    }

    public function build(int $version, FilterSelection $filter, Pagination $pagination): string
    {
        $filterPart = [
            'scope' => $filter->scope?->value,
            'type' => $filter->type?->value,
            'origin' => $filter->origin,
        ];
        ksort($filterPart);

        $filterJson = json_encode($filterPart, \JSON_THROW_ON_ERROR);
        $filterHash = md5($filterJson);

        return \sprintf(
            '%s:v%d:%s:p%d',
            self::KEY_PREFIX,
            $version,
            $filterHash,
            $pagination->currentPage,
        );
    }
}
