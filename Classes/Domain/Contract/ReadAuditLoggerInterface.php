<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;

interface ReadAuditLoggerInterface
{
    public function logQuickCheck(AuditContext $context): void;
}
