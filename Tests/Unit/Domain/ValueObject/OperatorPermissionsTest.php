<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use PHPUnit\Framework\TestCase;

final class OperatorPermissionsTest extends TestCase
{
    public function testAssertCanWritePassesWhenAllowed(): void
    {
        $perms = new OperatorPermissions(true, true, true, false);
        $perms->assertCanWrite();
        self::expectNotToPerformAssertions();
    }

    public function testAssertCanWriteThrowsWhenForbidden(): void
    {
        $perms = new OperatorPermissions(true, false, false, false);

        try {
            $perms->assertCanWrite();
            self::fail('Expected ForbiddenWriteException');
        } catch (ForbiddenWriteException $caught) {
            self::assertSame(DecisionWriteAction::WriteDenied, $caught->action);
            self::assertSame('no_operator_role', $caught->reasonCode);
        }
    }

    public function testAssertCanDeleteOwnDecision(): void
    {
        $perms = new OperatorPermissions(true, true, true, false);
        $decision = $this->decision('typo3');

        $perms->assertCanDelete($decision);
        self::expectNotToPerformAssertions();
    }

    public function testAssertCanDeleteForeignWhenAllowed(): void
    {
        $perms = new OperatorPermissions(true, true, true, true);
        $decision = $this->decision('cscli');

        $perms->assertCanDelete($decision);
        self::expectNotToPerformAssertions();
    }

    public function testAssertCanDeleteForeignBlockedByDefault(): void
    {
        $perms = new OperatorPermissions(true, true, true, false);
        $decision = $this->decision('cscli');

        try {
            $perms->assertCanDelete($decision);
            self::fail('Expected ForbiddenWriteException');
        } catch (ForbiddenWriteException $caught) {
            self::assertSame(DecisionWriteAction::DeleteDenied, $caught->action);
            self::assertSame('requires_admin', $caught->reasonCode);
        }
    }

    public function testAssertCanDeleteOwnBlockedWhenWriteDisabled(): void
    {
        $perms = new OperatorPermissions(true, false, false, false);
        $decision = $this->decision('typo3');

        $this->expectException(ForbiddenWriteException::class);
        $perms->assertCanDelete($decision);
    }

    private function decision(string $origin): Decision
    {
        return new Decision(
            id: new DecisionId(1),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.42',
            originalDuration: Duration::fromHours(1),
            expiresAt: new \DateTimeImmutable('2026-05-21T12:00:00+00:00'),
            origin: $origin,
            scenario: 'test',
        );
    }
}
