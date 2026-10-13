<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Application\Dto\IpCheckResult;
use Koh\CrowdsecBridge\Application\Support\Stopwatch;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\ReadAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\RemainingTime;

final readonly class CheckIpQueryHandler
{
    public function __construct(
        private DecisionRepositoryInterface $repository,
        private ReadAuditLoggerInterface $audit,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws \Koh\CrowdsecBridge\Domain\Exception\InvalidIpAddressException
     */
    public function handle(CheckIpQuery $query): IpCheckResult
    {
        $ip = new IpAddress($query->ipString);

        $stopwatch = Stopwatch::start();
        try {
            $decision = $this->repository->findByIp($ip);
        } catch (LapiUnavailableException|LapiAuthenticationException $caught) {
            $this->audit->logQuickCheck(new AuditContext(
                backendUserId: $query->backendUserId,
                backendUserName: $query->backendUserName,
                checkedIp: $ip,
                wasBanned: false,
                matchedDecisionId: null,
                latencyMs: $stopwatch->elapsedMs(),
            ));
            throw $caught;
        }

        $latencyMs = $stopwatch->elapsedMs();

        $result = new IpCheckResult(
            checkedIp: $ip,
            wasBanned: null !== $decision,
            matchedDecisionId: $decision?->id,
            remainingTime: null !== $decision ? RemainingTime::fromDecision($decision, $this->clock) : null,
        );

        $this->audit->logQuickCheck(new AuditContext(
            backendUserId: $query->backendUserId,
            backendUserName: $query->backendUserName,
            checkedIp: $ip,
            wasBanned: $result->wasBanned,
            matchedDecisionId: $result->matchedDecisionId,
            latencyMs: $latencyMs,
        ));

        return $result;
    }
}
