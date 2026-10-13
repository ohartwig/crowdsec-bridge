<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class DecisionWriteAuditContext
{
    /**
     * @param 'success'|'failed'|'not_found'|'denied' $status
     */
    public function __construct(
        public DecisionWriteAction $action,
        public int $backendUserId,
        public string $backendUserName,
        public string $status,
        public ?DecisionId $decisionId = null,
        /**
         * Set on a successful add, where it is the only id LAPI hands back —
         * decisions are created as part of an alert and the response carries
         * that alert's id. `cscli alerts inspect <id>` takes it.
         */
        public ?AlertId $alertId = null,
        public ?DecisionScope $scope = null,
        public ?DecisionType $type = null,
        public ?string $value = null,
        public ?Duration $duration = null,
        public ?string $originalOrigin = null,
        public ?Reason $reason = null,
        public int $latencyMs = 0,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @return array<string, int|string|bool|null>
     */
    public function toLogData(): array
    {
        return [
            'action' => $this->action->value,
            'status' => $this->status,
            'decision_id' => $this->decisionId?->toInt(),
            'alert_id' => $this->alertId?->toInt(),
            'scope' => $this->scope?->value,
            'type' => $this->type?->value,
            'value' => $this->value,
            'duration' => $this->duration?->toString(),
            'original_origin' => $this->originalOrigin,
            'reason' => $this->reason?->toString(),
            'latency_ms' => $this->latencyMs,
            'error' => $this->errorMessage,
        ];
    }
}
