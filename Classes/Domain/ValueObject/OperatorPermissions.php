<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;

/**
 * Permission snapshot for the current backend request. Read-only — built once per request by
 * the OperatorPermissionsFactory and passed to controller actions + Fluid templates.
 */
final readonly class OperatorPermissions
{
    public function __construct(
        public bool $canRead,
        public bool $canWrite,
        public bool $canDeleteOwn,
        public bool $canDeleteForeign,
    ) {}

    public function assertCanWrite(): void
    {
        if (!$this->canWrite) {
            throw ForbiddenWriteException::missingOperatorRole();
        }
    }

    public function assertCanDelete(Decision $snapshot): void
    {
        if (DecisionDraft::FIXED_ORIGIN === $snapshot->origin) {
            if (!$this->canDeleteOwn) {
                throw ForbiddenWriteException::missingOperatorRole();
            }

            return;
        }

        if (!$this->canDeleteForeign) {
            throw ForbiddenWriteException::foreignOriginRequiresAdmin($snapshot->origin);
        }
    }
}
