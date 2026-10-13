<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;

interface WriteAuditLoggerInterface
{
    public function logWrite(DecisionWriteAuditContext $context): void;
}
