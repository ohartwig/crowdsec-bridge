<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Command;

use Koh\CrowdsecBridge\Application\Command\AddDecisionCommand;
use Koh\CrowdsecBridge\Application\Command\AddDecisionCommandHandler;
use Koh\CrowdsecBridge\Domain\Contract\DecisionWriterInterface;
use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use PHPUnit\Framework\TestCase;

final class AddDecisionCommandHandlerTest extends TestCase
{
    /**
     * The id the handler passes on is the one LAPI actually returns for a
     * creation, and that is an alert id: decisions come into existence as part
     * of an alert and LAPI never reports the decision's own id back. Recording
     * it as a decision id would put a number in sys_log that addresses a
     * different row in CrowdSec's database.
     */
    public function testHappyPathCarriesTheAlertIdLapiReturned(): void
    {
        $writer = new InMemoryWriter(new AlertId(123), false);
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new AddDecisionCommandHandler($writer, $audit, $invalidator);
        $command = $this->command($this->permissions(canWrite: true), submitConsumed: false);

        $result = $handler->handle($command);

        self::assertSame(123, $result->alertId->toInt());
        self::assertCount(1, $audit->entries);
        self::assertSame(DecisionWriteAction::Add, $audit->entries[0]->action);
        self::assertSame('success', $audit->entries[0]->status);
        self::assertSame(123, $audit->entries[0]->alertId?->toInt());
        self::assertNull(
            $audit->entries[0]->decisionId,
            'an add has no decision id to record — claiming one would invent it',
        );
        self::assertSame(1, $invalidator->callCount, 'cache must be invalidated on success');
    }

    public function testForbiddenWriteAuditedAndThrown(): void
    {
        $writer = new InMemoryWriter(new AlertId(1), false);
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new AddDecisionCommandHandler($writer, $audit, $invalidator);
        $command = $this->command($this->permissions(canWrite: false));

        try {
            $handler->handle($command);
            self::fail('Expected ForbiddenWriteException');
        } catch (ForbiddenWriteException $caught) {
            self::assertSame(DecisionWriteAction::WriteDenied, $caught->action);
            self::assertCount(1, $audit->entries);
            self::assertSame(DecisionWriteAction::WriteDenied, $audit->entries[0]->action);
            self::assertFalse($writer->addCalled);
            self::assertSame(0, $invalidator->callCount, 'cache must NOT be invalidated on denied');
        }
    }

    public function testDuplicateSubmitRejected(): void
    {
        $writer = new InMemoryWriter(new AlertId(1), false);
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new AddDecisionCommandHandler($writer, $audit, $invalidator);
        $command = $this->command($this->permissions(canWrite: true), submitConsumed: true);

        try {
            $handler->handle($command);
            self::fail('Expected ForbiddenWriteException for duplicate submit');
        } catch (ForbiddenWriteException $caught) {
            self::assertSame(DecisionWriteAction::DuplicateSubmit, $caught->action);
            self::assertFalse($writer->addCalled);
            self::assertCount(1, $audit->entries);
            self::assertSame(0, $invalidator->callCount, 'cache must NOT be invalidated on duplicate submit');
        }
    }

    public function testLapiUnavailableAuditedAndRethrown(): void
    {
        $writer = new InMemoryWriter(null, true);
        $audit = new InMemoryWriteAudit();

        $invalidator = new SpyCacheInvalidator();
        $handler = new AddDecisionCommandHandler($writer, $audit, $invalidator);

        try {
            $handler->handle($this->command($this->permissions(canWrite: true)));
            self::fail('Expected LapiUnavailableException');
        } catch (LapiUnavailableException) {
            self::assertCount(1, $audit->entries);
            self::assertSame(DecisionWriteAction::Add, $audit->entries[0]->action);
            self::assertSame('failed', $audit->entries[0]->status);
            self::assertNotNull($audit->entries[0]->errorMessage);
            self::assertSame(0, $invalidator->callCount, 'cache must NOT be invalidated on LAPI failure');
        }
    }

    private function command(OperatorPermissions $perms, bool $submitConsumed = false): AddDecisionCommand
    {
        return new AddDecisionCommand(
            draft: new DecisionDraft(
                DecisionScope::Ip,
                DecisionType::Ban,
                '203.0.113.99',
                Duration::fromHours(24),
                new Reason('Smoke-Test-Reason'),
            ),
            backendUserId: 7,
            backendUserName: 'admin',
            permissions: $perms,
            submitTokenAlreadyConsumed: $submitConsumed,
        );
    }

    private function permissions(bool $canWrite): OperatorPermissions
    {
        return new OperatorPermissions(
            canRead: true,
            canWrite: $canWrite,
            canDeleteOwn: $canWrite,
            canDeleteForeign: false,
        );
    }
}

final class InMemoryWriter implements DecisionWriterInterface
{
    public bool $addCalled = false;
    public bool $deleteCalled = false;

    public function __construct(
        private ?AlertId $idToReturn,
        private bool $throwOnAdd,
    ) {}

    public function add(DecisionDraft $draft): AlertId
    {
        $this->addCalled = true;
        if ($this->throwOnAdd) {
            throw LapiUnavailableException::timeout('mock');
        }

        return $this->idToReturn ?? new AlertId(1);
    }

    public function delete(DecisionId $id): bool
    {
        $this->deleteCalled = true;

        return true;
    }
}

final class InMemoryWriteAudit implements WriteAuditLoggerInterface
{
    /** @var list<DecisionWriteAuditContext> */
    public array $entries = [];

    public function logWrite(DecisionWriteAuditContext $context): void
    {
        $this->entries[] = $context;
    }
}
