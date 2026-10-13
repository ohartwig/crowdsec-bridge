<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use PHPUnit\Framework\TestCase;

final class DecisionWriteAuditContextTest extends TestCase
{
    public function testAddSuccessSerializesAllFields(): void
    {
        $ctx = new DecisionWriteAuditContext(
            action: DecisionWriteAction::Add,
            backendUserId: 7,
            backendUserName: 'admin',
            status: 'success',
            decisionId: new DecisionId(42),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.99',
            duration: Duration::fromHours(24),
            reason: new Reason('Portscanner'),
            latencyMs: 87,
        );

        $data = $ctx->toLogData();

        self::assertSame('add', $data['action']);
        self::assertSame('success', $data['status']);
        self::assertSame(42, $data['decision_id']);
        self::assertSame('Ip', $data['scope']);
        self::assertSame('ban', $data['type']);
        self::assertSame('203.0.113.99', $data['value']);
        self::assertSame('24h', $data['duration']);
        self::assertSame('Portscanner', $data['reason']);
        self::assertSame(87, $data['latency_ms']);
        self::assertNull($data['original_origin']);
        self::assertNull($data['error']);
    }

    public function testDeleteDeniedSerializesOriginalOrigin(): void
    {
        $ctx = new DecisionWriteAuditContext(
            action: DecisionWriteAction::DeleteDenied,
            backendUserId: 3,
            backendUserName: 'op',
            status: 'denied',
            decisionId: new DecisionId(99),
            originalOrigin: 'cscli',
            latencyMs: 0,
            errorMessage: 'requires_admin',
        );

        $data = $ctx->toLogData();

        self::assertSame('delete_denied', $data['action']);
        self::assertSame('denied', $data['status']);
        self::assertSame('cscli', $data['original_origin']);
        self::assertSame('requires_admin', $data['error']);
        self::assertNull($data['scope']);
    }
}
