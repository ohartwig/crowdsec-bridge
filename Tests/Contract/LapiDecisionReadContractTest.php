<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiDecisionRepository;

/**
 * The decision read path, driven end to end through the production classes
 * against a real LAPI with nothing but the bouncer API key.
 *
 * This is the part of the bridge a bouncer key is actually entitled to:
 * GET /v1/decisions is the only route CrowdSec puts behind the API-key
 * middleware (plus /v1/decisions/stream, which the bridge does not use).
 * Everything asserted here is a statement about CrowdSec's wire format, not
 * about the bridge's behaviour — the bridge's behaviour is already covered by
 * the unit and functional suites.
 */
final class LapiDecisionReadContractTest extends LapiContractTestCase
{
    public function testListActiveDecisionsReturnsTheSeededDecision(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        $repository = $this->decisionRepository();
        $decisions = $repository->findActive(new FilterSelection(), new Pagination(1));

        $match = null;
        foreach ($decisions as $decision) {
            if ($decision->value === $ip) {
                $match = $decision;

                break;
            }
        }

        self::assertNotNull($match, \sprintf('GET /v1/decisions did not return the seeded decision for %s.', $ip));
        self::assertSame(DecisionScope::Ip, $match->scope);
        self::assertSame(DecisionType::Ban, $match->type);
        self::assertSame(self::SEED_ORIGIN, $match->origin);
        self::assertGreaterThan(0, $match->id->toInt());

        // The duration comes back as a Go duration string counted down from
        // "now" ("23h59m58s"), not as the "24h" that was sent. Duration's
        // regex has to keep accepting the compound form.
        self::assertGreaterThan(0, $match->originalDuration->toSeconds());
    }

    public function testScopeAndTypeFiltersAndPaginationAreAcceptedTogether(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        $repository = $this->decisionRepository();
        $filter = new FilterSelection(DecisionScope::Ip, DecisionType::Ban, self::SEED_ORIGIN);

        $decisions = $repository->findActive($filter, new Pagination(1));

        $values = array_map(static fn($decision): string => $decision->value, $decisions);
        self::assertContains($ip, $values, 'The filtered query dropped the seeded decision.');

        foreach ($decisions as $decision) {
            self::assertSame(DecisionScope::Ip, $decision->scope);
            self::assertSame(DecisionType::Ban, $decision->type);
        }
    }

    public function testFindByIpReturnsTheDecisionForThatAddress(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        $decision = $this->decisionRepository()->findByIp(new IpAddress($ip));

        self::assertNotNull($decision, \sprintf('GET /v1/decisions?ip=%s returned nothing.', $ip));
        self::assertSame($ip, $decision->value);
    }

    public function testFindByIdReturnsTheDecisionWithThatId(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        $repository = $this->decisionRepository();
        $seeded = $repository->findByIp(new IpAddress($ip));
        self::assertNotNull($seeded);

        $found = $repository->findById($seeded->id);

        self::assertNotNull($found);
        self::assertSame($seeded->id->toInt(), $found->id->toInt());
    }

    /**
     * An empty decision list is the JSON literal `null`, not `[]`.
     *
     * CrowdSec formats decisions into a nil Go slice and renders it directly,
     * so "no decisions" arrives as a four-byte body. LapiHttpClient special-
     * cases exactly that string; if CrowdSec ever switched to `[]`, this test
     * would stay green — but if it switched to `{}` or an error status, the
     * bridge would start reporting an unreachable LAPI for the most ordinary
     * answer there is.
     */
    public function testAnEmptyDecisionListIsAcceptedAsAnEmptyResult(): void
    {
        // 192.0.2.0/24 (RFC 5737 TEST-NET-1) is never seeded by this suite.
        $decision = $this->decisionRepository()->findByIp(new IpAddress('192.0.2.1'));

        self::assertNull($decision);
    }

    /**
     * The origin filter is spelled `origins`, plural, and `origin` is not part
     * of the vocabulary at all.
     *
     * FilterSelection::toLapiQueryParams() sends `origin`. CrowdSec's filter
     * loop (pkg/database/decisionfilter.go) has no default branch, so an
     * unknown parameter is neither an error nor a filter — it is silently
     * dropped, and the operator gets an unfiltered list that looks filtered.
     * That is why this is pinned here rather than left to be noticed: the
     * failure mode has no symptom. The day CrowdSec accepts `origin` too,
     * this test goes red and the note it carries can be deleted.
     */
    public function testTheDecisionsEndpointFiltersOnOriginsNotOnOrigin(): void
    {
        $typo3Ip = self::uniqueTestIp();
        $cscliIp = self::uniqueTestIp();

        $this->seedDecision($typo3Ip);
        $this->seedDecision($cscliIp, origin: 'cscli');

        $ignored = $this->valuesOf('/v1/decisions?origin=cscli');
        self::assertContains($typo3Ip, $ignored, '`origin` filtered the list — CrowdSec now understands the parameter.');
        self::assertContains($cscliIp, $ignored);

        $applied = $this->valuesOf('/v1/decisions?origins=cscli');
        self::assertNotContains($typo3Ip, $applied, '`origins` no longer filters — the parameter name changed.');
        self::assertContains($cscliIp, $applied);
    }

    /**
     * CrowdSec spells the autonomous-system scope `AS`; DecisionScope spells
     * it `As`.
     *
     * NormalizeScope() uppercases whatever is written, so a decision the
     * bridge creates with `As` is stored and returned as `AS` — and
     * DecisionScope::fromLapi() then throws a ValueError that
     * LapiResponseParser catches and turns into "skip this entry, log a
     * warning". The decision exists, blocks traffic, and is invisible in the
     * backend module. Nothing fails, which is why it needs pinning here.
     *
     * The assertion is on the wire, not on the parser: run through the parser
     * the entry simply is not there, and an assertion on an absence proves
     * nothing.
     */
    public function testTheAutonomousSystemScopeComesBackUppercased(): void
    {
        $asn = \sprintf('AS%d', random_int(64496, 64511)); // RFC 5398 documentation range.
        $this->seedDecision($asn, scope: 'As');

        $response = $this->sendRaw(
            'GET',
            '/v1/decisions?value=' . $asn,
            ['X-Api-Key' => self::bouncerKey()],
        );
        self::assertSame(200, $response->getStatusCode());

        $scopes = [];
        foreach (self::decodeJsonList($response) as $entry) {
            if (\is_array($entry) && isset($entry['scope']) && \is_string($entry['scope'])) {
                $scopes[] = $entry['scope'];
            }
        }

        self::assertSame(
            ['AS'],
            $scopes,
            'The AS scope is no longer returned uppercased — DecisionScope::As may now round-trip.',
        );
    }

    /**
     * `offset` on /v1/decisions is honoured, not merely accepted.
     *
     * Acceptance alone proves nothing here: the same endpoint accepts `origin`
     * and ignores it, and the alert endpoint rejects `offset` outright. The
     * decision filter is the odd one out — pkg/database/decisionfilter.go
     * (v1.7.8) carries explicit `limit` and `offset` cases that reach the SQL
     * query — and LapiDecisionRepository's server-side paging rests on exactly
     * that. Pinned the order-independent way: an offset beyond every row this
     * suite could ever seed must come back empty. If CrowdSec ever starts
     * dropping the parameter the far page would show the first rows again,
     * this goes red, and the repository has to switch to the client-side
     * slicing LapiAlertRepository already does.
     */
    public function testTheDecisionsEndpointHonoursOffsetNotJustAcceptsIt(): void
    {
        $ip = self::uniqueTestIp();
        $this->seedDecision($ip);

        $repository = $this->decisionRepository();

        $firstPage = $repository->findActive(new FilterSelection(), new Pagination(1));
        self::assertNotSame([], $firstPage, 'Page 1 does not even contain the seeded decision — the failure is not about offset.');

        // Page 1000 = offset 49950. The suite seeds at most 254 addresses per
        // run, so any row coming back here means the offset never reached the
        // query.
        $farPage = $repository->findActive(new FilterSelection(), new Pagination(1000));
        self::assertSame([], $farPage, 'GET /v1/decisions returned rows at offset 49950 — the `offset` parameter is being ignored, and paging silently shows page 1 everywhere.');
    }

    /**
     * @return list<string>
     */
    private function valuesOf(string $path): array
    {
        $response = $this->sendRaw('GET', $path, ['X-Api-Key' => self::bouncerKey()]);
        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        if ('null' === $body || '' === $body) {
            return [];
        }

        $values = [];
        foreach (self::decodeJsonList($response) as $entry) {
            if (\is_array($entry) && isset($entry['value']) && \is_string($entry['value'])) {
                $values[] = $entry['value'];
            }
        }

        return $values;
    }

    private function decisionRepository(): LapiDecisionRepository
    {
        return new LapiDecisionRepository($this->bridgeClient(), new LapiResponseParser());
    }
}
