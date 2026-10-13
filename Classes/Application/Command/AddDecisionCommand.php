<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Command;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;

final readonly class AddDecisionCommand
{
    public function __construct(
        public DecisionDraft $draft,
        public int $backendUserId,
        public string $backendUserName,
        public OperatorPermissions $permissions,
        public bool $submitTokenAlreadyConsumed = false,
    ) {}
}
