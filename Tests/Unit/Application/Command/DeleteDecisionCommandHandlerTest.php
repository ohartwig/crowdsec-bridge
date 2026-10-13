<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Command;

use Koh\CrowdsecBridge\Application\Command\DeleteDecisionCommand;
use Koh\CrowdsecBridge\Application\Command\DeleteDecisionCommandHandler;
use Koh\CrowdsecBridge\Domain\Contract\DecisionWriterInterface;
use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use PHPUnit\Framework\TestCase;

final class DeleteDecisionCommandHandlerTest extends TestCase
{
    public function testHappyPathOwnDecision(): void
    {
        $writer = new class implements DecisionWriterInterface {
            public function add(DecisionDraft $draft): AlertId
            {
                return new AlertId(1);
            }

            public function delete(DecisionId $id): bool
            {
                return true;
            }
        };
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new DeleteDecisionCommandHandler($writer, $audit, $invalidator);
        $result = $handler->handle($this->command(
            $this->decision('typo3'),
            $this->permissions(canDeleteOwn: true, canDeleteForeign: false),
        ));

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $audit->entries);
        self::assertSame(DecisionWriteAction::Delete, $audit->entries[0]->action);
        self::assertSame('success', $audit->entries[0]->status);
        self::assertSame('typo3', $audit->entries[0]->originalOrigin);
        self::assertSame(1, $invalidator->callCount, 'cache must be invalidated on success delete');
    }

    public function testNotFoundLapsToNotFoundStatus(): void
    {
        $writer = new class implements DecisionWriterInterface {
            public function add(DecisionDraft $draft): AlertId
            {
                return new AlertId(1);
            }

            public function delete(DecisionId $id): bool
            {
                return false;
            }
        };
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new DeleteDecisionCommandHandler($writer, $audit, $invalidator);
        $result = $handler->handle($this->command(
            $this->decision('typo3'),
            $this->permissions(canDeleteOwn: true, canDeleteForeign: false),
        ));

        self::assertTrue($result->isNotFound());
        self::assertSame('not_found', $audit->entries[0]->status);
        self::assertSame(1, $invalidator->callCount, 'cache must be invalidated even on not_found (state changed: it was there, now gone)');
    }

    public function testForeignOriginDeniedWithoutAdmin(): void
    {
        $writer = new class implements DecisionWriterInterface {
            public bool $deleteCalled = false;

            public function add(DecisionDraft $draft): AlertId
            {
                return new AlertId(1);
            }

            public function delete(DecisionId $id): bool
            {
                $this->deleteCalled = true;

                return true;
            }
        };
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new DeleteDecisionCommandHandler($writer, $audit, $invalidator);

        try {
            $handler->handle($this->command(
                $this->decision('cscli'),
                $this->permissions(canDeleteOwn: true, canDeleteForeign: false),
            ));
            self::fail('Expected ForbiddenWriteException');
        } catch (ForbiddenWriteException $caught) {
            self::assertSame(DecisionWriteAction::DeleteDenied, $caught->action);
            self::assertSame('requires_admin', $caught->reasonCode);
            self::assertCount(1, $audit->entries);
            self::assertSame('cscli', $audit->entries[0]->originalOrigin);
            self::assertFalse($writer->deleteCalled);
            self::assertSame(0, $invalidator->callCount, 'cache must NOT be invalidated on denied');
        }
    }

    public function testForeignOriginAllowedForAdmin(): void
    {
        $writer = new class implements DecisionWriterInterface {
            public function add(DecisionDraft $draft): AlertId
            {
                return new AlertId(1);
            }

            public function delete(DecisionId $id): bool
            {
                return true;
            }
        };
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new DeleteDecisionCommandHandler($writer, $audit, $invalidator);
        $result = $handler->handle($this->command(
            $this->decision('cscli'),
            $this->permissions(canDeleteOwn: true, canDeleteForeign: true),
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame(DecisionWriteAction::Delete, $audit->entries[0]->action);
        self::assertSame('cscli', $audit->entries[0]->originalOrigin);
        self::assertSame(1, $invalidator->callCount, 'admin foreign-delete success must invalidate cache');
    }

    private function command(Decision $snapshot, OperatorPermissions $perms): DeleteDecisionCommand
    {
        return new DeleteDecisionCommand(
            snapshot: $snapshot,
            backendUserId: 1,
            backendUserName: 'op',
            permissions: $perms,
        );
    }

    private function decision(string $origin): Decision
    {
        return new Decision(
            id: new DecisionId(42),
            scope: DecisionScope::Ip,
            type: DecisionType::Ban,
            value: '203.0.113.42',
            originalDuration: Duration::fromHours(1),
            expiresAt: new \DateTimeImmutable('2026-05-21T12:00:00+00:00'),
            origin: $origin,
            scenario: 'test',
        );
    }

    private function permissions(bool $canDeleteOwn, bool $canDeleteForeign): OperatorPermissions
    {
        return new OperatorPermissions(
            canRead: true,
            canWrite: $canDeleteOwn,
            canDeleteOwn: $canDeleteOwn,
            canDeleteForeign: $canDeleteForeign,
        );
    }
}
