<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionWriterInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;

final readonly class LapiDecisionWriter implements DecisionWriterInterface
{
    public function __construct(
        private LapiClientInterface $lapi,
        private ClockInterface $clock,
    ) {}

    /**
     * Creates the decision by posting the alert that carries it.
     *
     * `POST /v1/decisions` does not exist — CrowdSec answers it with 405 — and
     * what `POST /v1/alerts` hands back is a list of **alert** ids, not decision
     * ids. That is what this method returns, under its own type: an alert id is
     * the handle `cscli alerts inspect` takes, and passing it off as a decision
     * id would put a number in the audit trail that addresses a different row.
     * The decision's own id is available from the list screen, which is where
     * the delete path takes it from.
     */
    public function add(DecisionDraft $draft): AlertId
    {
        $response = $this->lapi->post('/v1/alerts', $draft->toLapiPayload($this->clock->now()));

        if ([] === $response) {
            throw LapiUnavailableException::invalidResponse('LAPI POST returned empty response');
        }

        // The ids arrive as bare array elements and the type varies by version —
        // string in some, int in others.
        $first = $response[0] ?? null;
        if (!\is_string($first) && !\is_int($first)) {
            throw LapiUnavailableException::invalidResponse('LAPI POST response[0] is not an id');
        }

        $idInt = (int) $first;
        if ($idInt <= 0) {
            throw LapiUnavailableException::invalidResponse('LAPI POST response[0] is not a positive id');
        }

        return new AlertId($idInt);
    }

    public function delete(DecisionId $id): bool
    {
        return $this->lapi->delete('/v1/decisions/' . $id->toInt());
    }
}
