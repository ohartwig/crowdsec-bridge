<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;

/**
 * The payload shapes of the LAPI routes the bridge calls but a bouncer key
 * cannot reach: /v1/alerts, /v1/alerts/{id}, /v1/heartbeat and
 * DELETE /v1/decisions/{id} all sit behind CrowdSec's JWT middleware.
 *
 * The requests here therefore carry a watcher token instead of X-Api-Key.
 * That is a deviation from what the bridge does, and a deliberate one: the
 * question this class answers is "does LapiResponseParser still understand
 * what comes back", and the only way to ask it against a real service is to
 * be allowed in. Whether the bridge's own credential is allowed in is the
 * separate question that LapiAuthSchemeContractTest asks.
 *
 * Paths, methods and query parameters are copied verbatim from the
 * repositories (LapiAlertRepository, LapiStatusRepository, LapiDecisionWriter)
 * so that a route rename or a vocabulary change shows up here.
 */
final class LapiResponseShapeContractTest extends LapiContractTestCase
{
    /**
     * The heartbeat carries no body and no version.
     *
     * LapiStatusRepository asks for a version and LapiResponseParser falls
     * back to "unknown" when it finds none — which is the permanent state:
     * the handler is `gctx.Status(http.StatusOK)`, nothing more. Worth
     * pinning, because the fallback hides it completely: the status widget
     * reads "reachable, version unknown" whether CrowdSec answers with a
     * version or with nothing at all.
     */
    public function testHeartbeatAnswersWithAnEmptyBody(): void
    {
        $response = $this->sendAsWatcher('GET', '/v1/heartbeat');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testAlertListEntriesParseIntoAlertValueObjects(): void
    {
        $ip = self::uniqueTestIp();
        $alertId = $this->seedDecision($ip);

        $response = $this->sendAsWatcher('GET', '/v1/alerts?scope=Ip&limit=50');
        self::assertSame(200, $response->getStatusCode());

        $alerts = new LapiResponseParser()->parseAlerts(self::decodeJsonArray($response), false);

        self::assertNotSame([], $alerts, 'GET /v1/alerts returned nothing after an alert was seeded.');

        $match = null;
        foreach ($alerts as $alert) {
            if ($alert->id->toInt() === $alertId) {
                $match = $alert;

                break;
            }
        }

        self::assertNotNull($match, \sprintf('Alert %d was missing from the list response.', $alertId));
        self::assertSame($ip, $match->source->value);
        self::assertSame(DecisionScope::Ip, $match->source->scope);
        self::assertSame(1, $match->decisionCount);
    }

    /**
     * `offset` is not part of the alert filter vocabulary.
     *
     * Unlike the decisions endpoint, which ignores what it does not know, the
     * alert filter has a default branch that rejects any unrecognised
     * parameter — and the rejection surfaces as a 500, because an invalid
     * filter is not one of the error kinds HandleDBErrors translates.
     * LapiAlertRepository sends `limit` *and* `offset` on every call, so the
     * paging parameter it adds is the thing that fails the request.
     *
     * The assertion is deliberately "not accepted" rather than a status code:
     * the day CrowdSec supports `offset`, this goes red and the bridge's
     * paging starts working, which is the outcome worth being told about.
     */
    public function testAlertListDoesNotAcceptTheOffsetParameterTheBridgeSends(): void
    {
        $withOffset = $this->sendAsWatcher('GET', '/v1/alerts?scope=Ip&limit=50&offset=0');
        $withoutOffset = $this->sendAsWatcher('GET', '/v1/alerts?scope=Ip&limit=50');

        self::assertSame(
            200,
            $withoutOffset->getStatusCode(),
            'The alert query without `offset` should be accepted; if it is not, the failure is not about paging.',
        );
        self::assertNotSame(
            200,
            $withOffset->getStatusCode(),
            'GET /v1/alerts now accepts `offset` — LapiAlertRepository can page for real.',
        );
    }

    public function testAlertDetailParsesIncludingItsDecisions(): void
    {
        $ip = self::uniqueTestIp();
        $alertId = $this->seedDecision($ip);

        $response = $this->sendAsWatcher('GET', '/v1/alerts/' . $alertId);
        self::assertSame(200, $response->getStatusCode());

        $alert = new LapiResponseParser()->parseAlert(self::decodeJsonArray($response), true);

        self::assertNotNull($alert);
        self::assertSame($alertId, $alert->id->toInt());
        self::assertSame($ip, $alert->source->value);
        self::assertCount(1, $alert->decisions);
        self::assertSame($ip, $alert->decisions[0]->value);
        self::assertSame(self::SEED_ORIGIN, $alert->decisions[0]->origin);
    }

    /**
     * A missing alert is a 404, which is what LapiAlertRepository catches to
     * turn into a null result.
     */
    public function testUnknownAlertIdIsNotFound(): void
    {
        $response = $this->sendAsWatcher('GET', '/v1/alerts/999999999');

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Deleting a decision answers 200 with a count, not with the deleted
     * decision and not with an empty body.
     *
     * LapiHttpClient::delete() only looks at the status code, so the body is
     * not load-bearing today — but the count is the only evidence that a row
     * was hit at all, and a change from "1" to 1 (string to number) is the
     * kind of thing that quietly breaks the next caller.
     */
    public function testDeletingADecisionAnswersWithADeletedCount(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        // Listing decisions is bouncer territory; the watcher token would be
        // turned away by the API-key middleware on this route.
        $listed = $this->sendRaw('GET', '/v1/decisions?ip=' . $ip, ['X-Api-Key' => self::bouncerKey()]);
        self::assertSame(200, $listed->getStatusCode());

        $decisionId = null;
        foreach (self::decodeJsonList($listed) as $entry) {
            if (\is_array($entry) && isset($entry['id']) && \is_int($entry['id'])) {
                $decisionId = $entry['id'];

                break;
            }
        }

        self::assertNotNull($decisionId, \sprintf('No decision found for %s to delete.', $ip));

        $deleted = $this->sendAsWatcher('DELETE', '/v1/decisions/' . $decisionId);

        self::assertSame(200, $deleted->getStatusCode());
        self::assertSame(['nbDeleted' => '1'], self::decodeJsonArray($deleted));
    }
}
