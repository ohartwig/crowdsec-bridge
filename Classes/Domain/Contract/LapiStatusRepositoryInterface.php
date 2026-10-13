<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\LapiStatus;

interface LapiStatusRepositoryInterface
{
    /**
     * Returns the current LAPI status. Implementations MUST NOT throw on LAPI
     * connectivity failures — they should return `LapiStatus::unreachable(...)`
     * with the appropriate error code (`timeout`, `auth_failed`, `invalid_response`).
     */
    public function findStatus(\DateTimeImmutable $checkedAt): LapiStatus;
}
