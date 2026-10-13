<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Dto;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\RemainingTime;

final readonly class IpCheckResult
{
    public function __construct(
        public IpAddress $checkedIp,
        public bool $wasBanned,
        public ?DecisionId $matchedDecisionId,
        public ?RemainingTime $remainingTime,
    ) {}
}
