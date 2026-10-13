<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which credential each route the bridge calls actually accepts.
 *
 * LapiHttpClient sends exactly one credential header per request; in its
 * default bouncer role that is X-Api-Key, the key from LapiKeyResolver — and
 * CrowdSec puts only two routes behind that middleware. This class walks every
 * path/method pair the repositories build and records, against a real LAPI,
 * whether that one credential gets in. The machine role that the refusals
 * below forced into existence is exercised in LapiMachineRoleContractTest.
 *
 * Several of these assertions therefore say "this is refused". They are not an
 * endorsement of the refusal; they are the vendor's contract written down
 * where a change to it is noticed. Each one goes red the day CrowdSec opens
 * the route to bouncers — which is precisely when the bridge's corresponding
 * feature would start working and somebody should be told.
 */
final class LapiAuthSchemeContractTest extends LapiContractTestCase
{
    /**
     * GET /v1/decisions is the one route the bridge calls that a bouncer key
     * is entitled to. If this ever stops being true, the backend module loses
     * its last working screen.
     */
    public function testDecisionListAcceptsTheBouncerApiKey(): void
    {
        $response = $this->sendRaw('GET', '/v1/decisions', ['X-Api-Key' => self::bouncerKey()]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDecisionListRefusesAnUnknownApiKey(): void
    {
        $response = $this->sendRaw('GET', '/v1/decisions', ['X-Api-Key' => 'not-a-registered-bouncer-key']);

        self::assertSame(403, $response->getStatusCode());
    }

    #[DataProvider('watcherOnlyRoutes')]
    public function testWatcherOnlyRoutesRefuseTheBouncerApiKey(string $method, string $path): void
    {
        $response = $this->sendRaw($method, $path, ['X-Api-Key' => self::bouncerKey()]);

        self::assertSame(
            401,
            $response->getStatusCode(),
            \sprintf(
                '%s %s answered %d for a bouncer key. The bridge sends nothing else, so this route is either newly reachable or newly broken.',
                $method,
                $path,
                $response->getStatusCode(),
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function watcherOnlyRoutes(): iterable
    {
        // LapiStatusRepository::findStatus()
        yield 'heartbeat' => ['GET', '/v1/heartbeat'];
        // LapiAlertRepository::findAlerts()
        yield 'alert list' => ['GET', '/v1/alerts?scope=Ip&limit=50'];
        // LapiAlertRepository::findAlertById()
        yield 'alert detail' => ['GET', '/v1/alerts/1'];
        // LapiDecisionWriter::delete()
        yield 'decision delete' => ['DELETE', '/v1/decisions/1'];
    }

    /**
     * There is no POST /v1/decisions.
     *
     * LapiDecisionWriter::add() posts the draft to /v1/decisions. CrowdSec
     * registers that path for GET, HEAD (bouncers) and DELETE (watchers) and
     * nothing else, so the request is answered by gin's method-not-allowed
     * handler before any credential is looked at. Decisions are created by
     * posting an alert that carries them — which is, tellingly, exactly the
     * shape DecisionDraft::toLapiPayload() builds.
     */
    public function testThereIsNoPostRouteForDecisions(): void
    {
        $response = $this->sendRaw(
            'POST',
            '/v1/decisions',
            ['X-Api-Key' => self::bouncerKey()],
            '[]',
        );

        self::assertSame(
            405,
            $response->getStatusCode(),
            'POST /v1/decisions is answered — CrowdSec grew a route the bridge has been assuming all along.',
        );
    }
}
