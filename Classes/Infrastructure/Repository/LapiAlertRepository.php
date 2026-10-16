<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\AlertRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiNotFoundException;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;

final readonly class LapiAlertRepository implements AlertRepositoryInterface
{
    public function __construct(
        private LapiClientInterface $lapi,
        private LapiResponseParser $parser,
    ) {}

    /**
     * `offset` is not part of the alert filter's vocabulary, and unlike the
     * decisions endpoint — which drops what it does not recognise — the alert
     * filter has a default branch that rejects it. The rejection surfaces as a
     * 500, because an invalid filter is not one of the error kinds
     * HandleDBErrors translates, so sending `offset` did not degrade paging:
     * it failed every alert-list request the module ever made.
     *
     * There is no server-side paging to fall back to, so paging is done here:
     * ask for everything up to the end of the requested page and drop the pages
     * before it. That costs one full page of parsing per page skipped, which is
     * the honest price of an endpoint that only understands `limit`. It also
     * means page N and page N+1 are two independent snapshots — an alert
     * arriving in between shifts the boundary, exactly as it does today.
     *
     * Pinned by Tests/Contract/LapiResponseShapeContractTest::
     * testAlertListDoesNotAcceptTheOffsetParameterTheBridgeSends().
     *
     * The limit is capped at Pagination::MAX_SNAPSHOT_ITEMS whatever page is
     * asked for. The handler already clamps the page; this is the second lock
     * on the same door, so that no caller of this repository can turn a page
     * number into a full dump of the LAPI's alert table (AUD-002-F02).
     */
    public function findAlerts(AlertFilterSelection $filter, Pagination $pagination): array
    {
        $query = $filter->toLapiQueryParams();
        $query['limit'] = (string) min($pagination->offset() + $pagination->pageSize(), Pagination::MAX_SNAPSHOT_ITEMS);

        $body = $this->lapi->get('/v1/alerts', $query);

        $alerts = $this->parser->parseAlerts([] === $body ? null : $body, false);

        return \array_slice($alerts, $pagination->offset());
    }

    public function findAlertById(AlertId $id): ?Alert
    {
        try {
            $body = $this->lapi->get('/v1/alerts/' . $id->toInt());
        } catch (LapiNotFoundException) {
            return null;
        }

        return $this->parser->parseAlert([] === $body ? null : $body, true);
    }
}
