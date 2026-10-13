<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Command;

use Koh\CrowdsecBridge\Application\Dto\WriteResult;
use Koh\CrowdsecBridge\Application\Support\Stopwatch;
use Koh\CrowdsecBridge\Domain\Contract\DecisionCacheInvalidatorInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionWriterInterface;
use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAuditContext;

final readonly class DeleteDecisionCommandHandler
{
    public function __construct(
        private DecisionWriterInterface $writer,
        private WriteAuditLoggerInterface $audit,
        private DecisionCacheInvalidatorInterface $cacheInvalidator,
    ) {}

    public function handle(DeleteDecisionCommand $command): WriteResult
    {
        $snapshot = $command->snapshot;

        try {
            $command->permissions->assertCanDelete($snapshot);
        } catch (ForbiddenWriteException $denied) {
            $this->audit->logWrite(new DecisionWriteAuditContext(
                action: $denied->action,
                backendUserId: $command->backendUserId,
                backendUserName: $command->backendUserName,
                status: 'denied',
                decisionId: $snapshot->id,
                scope: $snapshot->scope,
                type: $snapshot->type,
                value: $snapshot->value,
                duration: $snapshot->originalDuration,
                originalOrigin: $snapshot->origin,
                errorMessage: $denied->reasonCode,
            ));
            throw $denied;
        }

        $stopwatch = Stopwatch::start();

        try {
            $deleted = $this->writer->delete($snapshot->id);
        } catch (LapiUnavailableException|LapiAuthenticationException $caught) {
            $this->audit->logWrite(new DecisionWriteAuditContext(
                action: DecisionWriteAction::Delete,
                backendUserId: $command->backendUserId,
                backendUserName: $command->backendUserName,
                status: 'failed',
                decisionId: $snapshot->id,
                originalOrigin: $snapshot->origin,
                latencyMs: $stopwatch->elapsedMs(),
                errorMessage: $caught->getMessage(),
            ));
            throw $caught;
        }

        $this->audit->logWrite(new DecisionWriteAuditContext(
            action: DecisionWriteAction::Delete,
            backendUserId: $command->backendUserId,
            backendUserName: $command->backendUserName,
            status: $deleted ? 'success' : 'not_found',
            decisionId: $snapshot->id,
            scope: $snapshot->scope,
            type: $snapshot->type,
            value: $snapshot->value,
            duration: $snapshot->originalDuration,
            originalOrigin: $snapshot->origin,
            latencyMs: $stopwatch->elapsedMs(),
        ));

        $this->cacheInvalidator->invalidate();

        return WriteResult::ofDelete($deleted, $snapshot->id);
    }
}
