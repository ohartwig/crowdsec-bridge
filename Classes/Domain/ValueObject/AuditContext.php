<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class AuditContext
{
    public function __construct(
        public int $backendUserId,
        public string $backendUserName,
        public IpAddress $checkedIp,
        public bool $wasBanned,
        public ?DecisionId $matchedDecisionId,
        public int $latencyMs,
    ) {}

    /**
     * @return array<string, int|string|bool|null>
     */
    public function toLogData(): array
    {
        return [
            'ip' => $this->checkedIp->value,
            'ip_version' => $this->checkedIp->version,
            'was_banned' => $this->wasBanned,
            'decision_id' => $this->matchedDecisionId?->toInt(),
            'latency_ms' => $this->latencyMs,
        ];
    }
}
