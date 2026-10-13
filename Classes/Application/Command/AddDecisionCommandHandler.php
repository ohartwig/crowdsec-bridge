<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Command;

use Koh\CrowdsecBridge\Application\Dto\AddDecisionResult;
use Koh\CrowdsecBridge\Application\Support\Stopwatch;
use Koh\CrowdsecBridge\Domain\Contract\DecisionCacheInvalidatorInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionWriterInterface;
use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;

final readonly class AddDecisionCommandHandler
{
    public function __construct(
        private DecisionWriterInterface $writer,
        private WriteAuditLoggerInterface $audit,
        private DecisionCacheInvalidatorInterface $cacheInvalidator,
    ) {}

    public function handle(AddDecisionCommand $command): AddDecisionResult
    {
        try {
            $command->permissions->assertCanWrite();
        } catch (ForbiddenWriteException $denied) {
            $this->audit->logWrite(new DecisionWriteAuditContext(
                action: DecisionWriteAction::WriteDenied,
                backendUserId: $command->backendUserId,
                backendUserName: $command->backendUserName,
                status: 'denied',
                scope: $command->draft->scope,
                type: $command->draft->type,
                value: $command->draft->normalisedValue(),
                duration: $command->draft->duration,
                reason: $command->draft->reason,
                errorMessage: $denied->reasonCode,
            ));
            throw $denied;
        }

        if ($command->submitTokenAlreadyConsumed) {
            $this->audit->logWrite(new DecisionWriteAuditContext(
                action: DecisionWriteAction::DuplicateSubmit,
                backendUserId: $command->backendUserId,
                backendUserName: $command->backendUserName,
                status: 'denied',
                scope: $command->draft->scope,
                type: $command->draft->type,
                value: $command->draft->normalisedValue(),
            ));
            throw ForbiddenWriteException::duplicateSubmit();
        }

        $stopwatch = Stopwatch::start();

        try {
            $alertId = $this->writer->add($command->draft);
        } catch (LapiUnavailableException|LapiAuthenticationException $caught) {
            $this->audit->logWrite(new DecisionWriteAuditContext(
                action: DecisionWriteAction::Add,
                backendUserId: $command->backendUserId,
                backendUserName: $command->backendUserName,
                status: 'failed',
                scope: $command->draft->scope,
                type: $command->draft->type,
                value: $command->draft->normalisedValue(),
                duration: $command->draft->duration,
                reason: $command->draft->reason,
                latencyMs: $stopwatch->elapsedMs(),
                errorMessage: $caught->getMessage(),
            ));
            throw $caught;
        }

        $this->audit->logWrite(new DecisionWriteAuditContext(
            action: DecisionWriteAction::Add,
            backendUserId: $command->backendUserId,
            backendUserName: $command->backendUserName,
            status: 'success',
            alertId: $alertId,
            scope: $command->draft->scope,
            type: $command->draft->type,
            value: $command->draft->normalisedValue(),
            duration: $command->draft->duration,
            reason: $command->draft->reason,
            latencyMs: $stopwatch->elapsedMs(),
        ));

        $this->cacheInvalidator->invalidate();

        return new AddDecisionResult($alertId);
    }
}
