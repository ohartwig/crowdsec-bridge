<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Audit;

use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Writes a sys_log row per write attempt (success, failure, denial).
 * Type column uses one of the crowdsec_bridge:* constants so the entries are filterable
 * in Backend → System → Log.
 */
final readonly class SysLogWriteAuditLogger implements WriteAuditLoggerInterface
{
    public function __construct(private ConnectionPool $connectionPool) {}

    public function logWrite(DecisionWriteAuditContext $context): void
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_log');

        $details = $this->renderDetails($context);
        $level = $this->mapLevel($context->action, $context->status);

        $connection->insert('sys_log', [
            'tstamp' => time(),
            'userid' => $context->backendUserId,
            'workspace' => 0,
            'action' => 0,
            'type' => $context->action->toSysLogType(),
            'error' => 'failed' === $context->status ? 1 : 0,
            'details_nr' => 0,
            'details' => $details,
            'log_data' => serialize($context->toLogData()),
            'channel' => 'security',
            'level' => $level,
            'message' => $details,
        ]);
    }

    private function renderDetails(DecisionWriteAuditContext $context): string
    {
        $base = \sprintf(
            '[%s] %s — user=%s (uid:%d) — status=%s',
            $context->action->value,
            $this->renderDecisionFragment($context),
            LogLineSanitizer::clean($context->backendUserName),
            $context->backendUserId,
            $context->status,
        );

        $extras = [];
        if (null !== $context->originalOrigin) {
            $extras[] = 'original_origin=' . LogLineSanitizer::clean($context->originalOrigin);
        }
        if (null !== $context->errorMessage) {
            $extras[] = 'error=' . LogLineSanitizer::clean($context->errorMessage);
        }
        if ($context->latencyMs > 0) {
            $extras[] = 'latency=' . $context->latencyMs . 'ms';
        }

        return [] === $extras ? $base : $base . ' — ' . implode(' ', $extras);
    }

    private function renderDecisionFragment(DecisionWriteAuditContext $context): string
    {
        if (DecisionWriteAction::Delete === $context->action || DecisionWriteAction::DeleteDenied === $context->action) {
            return null !== $context->decisionId
                ? 'decision_id=' . $context->decisionId->toInt()
                : 'decision_id=null';
        }

        if (DecisionWriteAction::Add === $context->action) {
            // The alert id is the only handle LAPI returns for a creation, so it
            // is the only way back from this log line to the row in CrowdSec.
            return \sprintf(
                '%s/%s %s %s alert_id=%s',
                $context->scope->value ?? '?',
                $context->type->value ?? '?',
                LogLineSanitizer::clean($context->value ?? '?'),
                $context->duration?->toString() ?? '?',
                $context->alertId?->toString() ?? 'null',
            );
        }

        return 'no_decision_context';
    }

    /**
     * @param 'success'|'failed'|'not_found'|'denied' $status
     */
    private function mapLevel(DecisionWriteAction $action, string $status): string
    {
        if ('failed' === $status || 'denied' === $status) {
            return 'warning';
        }
        if (DecisionWriteAction::CsrfDenied === $action
            || DecisionWriteAction::WriteDenied === $action
            || DecisionWriteAction::DeleteDenied === $action
            || DecisionWriteAction::DuplicateSubmit === $action
        ) {
            return 'warning';
        }

        return 'info';
    }
}
