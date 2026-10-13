<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Audit;

use Koh\CrowdsecBridge\Domain\Contract\ReadAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists Quick-Check audit entries to sys_log (Constitution principle V + Feature 1 FR-016).
 * Type column is set to "crowdsec_bridge:quickcheck" for easy filtering in System → Log.
 */
final readonly class SysLogReadAuditLogger implements ReadAuditLoggerInterface
{
    public const SYS_LOG_TYPE = 'crowdsec_bridge:quickcheck';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function logQuickCheck(AuditContext $context): void
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_log');
        $connection->insert('sys_log', [
            'tstamp' => time(),
            'userid' => $context->backendUserId,
            'workspace' => 0,
            'action' => 0,
            'type' => self::SYS_LOG_TYPE,
            'error' => 0,
            'details_nr' => 0,
            'details' => $this->renderDetails($context),
            'log_data' => serialize($context->toLogData()),
            'channel' => 'security',
            'level' => 'info',
            'message' => $this->renderDetails($context),
        ]);
    }

    private function renderDetails(AuditContext $context): string
    {
        return \sprintf(
            'Quick-Check IP=%s → %s; decision_id=%s; user=%s (uid:%d); latency=%dms',
            LogLineSanitizer::clean($context->checkedIp->value),
            $context->wasBanned ? 'banned' : 'not_banned',
            $context->matchedDecisionId?->toString() ?? 'null',
            LogLineSanitizer::clean($context->backendUserName),
            $context->backendUserId,
            $context->latencyMs,
        );
    }
}
