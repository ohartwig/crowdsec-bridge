<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;

interface DecisionWriterInterface
{
    /**
     * @return AlertId the alert that now carries the decision — LAPI creates
     *                 decisions only as part of an alert and answers with that
     *                 alert's id, never with the decision's
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function add(DecisionDraft $draft): AlertId;

    /**
     * @return bool true on HTTP 200 (deleted), false on HTTP 404 (already gone)
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function delete(DecisionId $id): bool;
}
