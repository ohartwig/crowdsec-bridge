<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;

final class AuditContextTest extends TestCase
{
    public function testToLogDataSerializesAllRelevantFields(): void
    {
        $ctx = new AuditContext(
            backendUserId: 7,
            backendUserName: 'admin',
            checkedIp: new IpAddress('2001:db8::1'),
            wasBanned: true,
            matchedDecisionId: new DecisionId(42),
            latencyMs: 87,
        );

        $data = $ctx->toLogData();

        self::assertSame('2001:db8::1', $data['ip']);
        self::assertSame(6, $data['ip_version']);
        self::assertTrue($data['was_banned']);
        self::assertSame(42, $data['decision_id']);
        self::assertSame(87, $data['latency_ms']);
    }

    public function testToLogDataHandlesNullDecision(): void
    {
        $ctx = new AuditContext(
            backendUserId: 1,
            backendUserName: 'user',
            checkedIp: new IpAddress('1.1.1.1'),
            wasBanned: false,
            matchedDecisionId: null,
            latencyMs: 12,
        );

        $data = $ctx->toLogData();

        self::assertFalse($data['was_banned']);
        self::assertNull($data['decision_id']);
    }
}
