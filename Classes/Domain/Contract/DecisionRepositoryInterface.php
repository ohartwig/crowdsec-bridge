<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

interface DecisionRepositoryInterface
{
    /**
     * @return list<Decision>
     */
    public function findActive(FilterSelection $filter, Pagination $pagination): array;

    public function findByIp(IpAddress $ip): ?Decision;

    /**
     * Snapshot of a single decision required for delete-action origin verification.
     * Returns null if no longer in the active set (LAPI returned empty or 404).
     */
    public function findById(\Koh\CrowdsecBridge\Domain\ValueObject\DecisionId $id): ?Decision;
}
