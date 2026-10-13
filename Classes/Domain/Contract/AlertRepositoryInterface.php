<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;

interface AlertRepositoryInterface
{
    /**
     * @return list<Alert> alerts with empty decisions[] and only decisionCount set (list mode)
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function findAlerts(AlertFilterSelection $filter, Pagination $pagination): array;

    /**
     * @return Alert|null fully parsed alert (including decisions[]), or null if LAPI returned 404
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function findAlertById(AlertId $id): ?Alert;
}
