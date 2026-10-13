<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Audit;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use Koh\CrowdsecBridge\Infrastructure\Audit\SysLogWriteAuditLogger;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SysLogWriteAuditLoggerTest extends TestCase
{
    public function testAddSuccessInsertsExpectedRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::equalTo('sys_log'),
                self::callback(static function (array $row): bool {
                    return 'crowdsec_bridge:decision_add' === $row['type']
                        && 0 === $row['error']
                        && 'info' === $row['level']
                        && 7 === $row['userid']
                        && str_contains((string) $row['details'], 'Ip/ban 203.0.113.99 24h')
                        && str_contains((string) $row['details'], 'status=success')
                        && str_contains((string) $row['details'], 'admin')
                        && \is_string($row['log_data'])
                        && \is_int($row['tstamp']);
                }),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        new SysLogWriteAuditLogger($pool)->logWrite(new DecisionWriteAuditContext(
            action: DecisionWriteAction::Add,
            backendUserId: 7,
            backendUserName: 'admin',
            status: 'success',
            decisionId: new DecisionId(42),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.99',
            duration: Duration::fromHours(24),
            reason: new Reason('Smoke-Test'),
            latencyMs: 87,
        ));
    }

    public function testDeleteNotFoundSetsInfoLevel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::equalTo('sys_log'),
                self::callback(static function (array $row): bool {
                    return 'crowdsec_bridge:decision_delete' === $row['type']
                        && str_contains((string) $row['details'], 'decision_id=99')
                        && str_contains((string) $row['details'], 'status=not_found')
                        && 0 === $row['error'];
                }),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        new SysLogWriteAuditLogger($pool)->logWrite(new DecisionWriteAuditContext(
            action: DecisionWriteAction::Delete,
            backendUserId: 1,
            backendUserName: 'op',
            status: 'not_found',
            decisionId: new DecisionId(99),
        ));
    }

    public function testWriteDeniedHasWarningLevel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::equalTo('sys_log'),
                self::callback(static function (array $row): bool {
                    return 'crowdsec_bridge:write_denied' === $row['type']
                        && 'warning' === $row['level']
                        && str_contains((string) $row['details'], 'status=denied');
                }),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        new SysLogWriteAuditLogger($pool)->logWrite(new DecisionWriteAuditContext(
            action: DecisionWriteAction::WriteDenied,
            backendUserId: 5,
            backendUserName: 'viewer',
            status: 'denied',
            errorMessage: 'no_operator_role',
        ));
    }

    public function testCsrfDeniedHasCorrectType(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::equalTo('sys_log'),
                self::callback(static function (array $row): bool {
                    return 'crowdsec_bridge:csrf_denied' === $row['type']
                        && 'warning' === $row['level'];
                }),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        new SysLogWriteAuditLogger($pool)->logWrite(new DecisionWriteAuditContext(
            action: DecisionWriteAction::CsrfDenied,
            backendUserId: 5,
            backendUserName: 'attacker',
            status: 'denied',
            errorMessage: 'csrf_token_invalid',
        ));
    }
}
