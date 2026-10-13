<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Command;

use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;

final readonly class DeleteDecisionCommand
{
    public function __construct(
        public Decision $snapshot,
        public int $backendUserId,
        public string $backendUserName,
        public OperatorPermissions $permissions,
    ) {}
}
