<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Audit;

use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Infrastructure\Audit\SysLogReadAuditLogger;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Verifies that SysLogReadAuditLogger writes the expected shape into sys_log.
 * The Connection / ConnectionPool dependencies are minimally mocked (PHPUnit createMock).
 */
final class SysLogReadAuditLoggerTest extends TestCase
{
    public function testInsertsExpectedRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::equalTo('sys_log'),
                self::callback(static function (array $row): bool {
                    return 7 === $row['userid']
                        && SysLogReadAuditLogger::SYS_LOG_TYPE === $row['type']
                        && str_contains((string) $row['details'], '203.0.113.42')
                        && str_contains((string) $row['details'], 'banned')
                        && str_contains((string) $row['details'], 'admin')
                        && \is_string($row['log_data'])
                        && \is_int($row['tstamp']);
                }),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $logger = new SysLogReadAuditLogger($pool);
        $logger->logQuickCheck(new AuditContext(
            backendUserId: 7,
            backendUserName: 'admin',
            checkedIp: new IpAddress('203.0.113.42'),
            wasBanned: true,
            matchedDecisionId: new DecisionId(42),
            latencyMs: 87,
        ));
    }

    public function testNotBannedDetailsContainNotBanned(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                self::equalTo('sys_log'),
                self::callback(static function (array $row): bool {
                    return str_contains((string) $row['details'], 'not_banned')
                        && str_contains((string) $row['details'], 'decision_id=null');
                }),
            );
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        new SysLogReadAuditLogger($pool)->logQuickCheck(new AuditContext(
            backendUserId: 1,
            backendUserName: 'user',
            checkedIp: new IpAddress('198.51.100.5'),
            wasBanned: false,
            matchedDecisionId: null,
            latencyMs: 12,
        ));
    }
}
