<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiNotFoundException;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiAlertRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Alerts are the why behind a decision — the list an operator reads before
 * deciding whether a ban was justified.
 *
 * The detail lookup has the one branch worth pinning: an alert that no longer
 * exists comes back as null rather than as an error, because CrowdSec prunes
 * alerts on its own schedule and a stale link in the module is normal.
 */
final class LapiAlertRepositoryTest extends TestCase
{
    /** @var list<array{string, array<string, string>}> */
    private array $requests = [];

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $body
     */
    private function repositoryAnswering(array $body, ?\Throwable $failWith = null): LapiAlertRepository
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('get')->willReturnCallback(
            function (string $path, array $query = []) use ($body, $failWith): array {
                $this->requests[] = [$path, $query];
                if (null !== $failWith) {
                    throw $failWith;
                }

                return $body;
            },
        );

        return new LapiAlertRepository($client, new LapiResponseParser());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function alertBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'scenario' => 'crowdsecurity/ssh-bf',
            'created_at' => '2026-07-30T08:00:00Z',
            'source' => ['scope' => 'Ip', 'value' => '203.0.113.42'],
            'decisions' => [],
        ], $overrides);
    }

    /**
     * `offset` must not appear in an alert query.
     *
     * This test used to require it, and the mock behind it happily accepted it.
     * A real CrowdSec answers `offset` on /v1/alerts with a 500 — the alert
     * filter, unlike the decision filter, rejects what it does not recognise —
     * so the parameter the module added for paging was failing every alert-list
     * request it ever made. There is no server-side paging to ask for, only a
     * `limit`, so the page is cut out here.
     */
    #[Test]
    public function theQueryAsksForEverythingUpToThePageAndNeverSendsAnOffset(): void
    {
        $this->repositoryAnswering([$this->alertBody()])->findAlerts(
            new AlertFilterSelection(DecisionScope::Ip, 'ssh'),
            new Pagination(3),
        );

        [$path, $query] = $this->requests[0];
        self::assertSame('/v1/alerts', $path);
        self::assertSame('150', $query['limit'], 'three pages of fifty, because only limit is understood');
        self::assertArrayNotHasKey('offset', $query, 'sending offset is answered with HTTP 500');
        self::assertArrayHasKey('scope', $query);
    }

    /**
     * Paging without an offset parameter still has to page: the entries before
     * the requested one are dropped here, or every page would show page one.
     */
    #[Test]
    public function thePagesBeforeTheRequestedOneAreDroppedFromTheAnswer(): void
    {
        $bodies = [];
        for ($id = 1; $id <= 120; ++$id) {
            $bodies[] = $this->alertBody(['id' => $id]);
        }

        $alerts = $this->repositoryAnswering($bodies)->findAlerts(new AlertFilterSelection(), new Pagination(3));

        self::assertSame(
            range(101, 120),
            array_map(static fn($a): int => $a->id->toInt(), $alerts),
            'page 3 starts at the hundred-and-first alert',
        );
    }

    #[Test]
    public function anEmptyAnswerYieldsNoAlerts(): void
    {
        self::assertSame(
            [],
            $this->repositoryAnswering([])->findAlerts(new AlertFilterSelection(), new Pagination(1)),
        );
    }

    #[Test]
    public function alertsAreParsed(): void
    {
        $alerts = $this->repositoryAnswering([
            $this->alertBody(['id' => 1]),
            $this->alertBody(['id' => 2]),
        ])->findAlerts(new AlertFilterSelection(), new Pagination(1));

        self::assertSame([1, 2], array_map(static fn($a): int => $a->id->toInt(), $alerts));
    }

    #[Test]
    public function anAlertIsFetchedByItsOwnEndpoint(): void
    {
        $alert = $this->repositoryAnswering($this->alertBody(['id' => 7]))
            ->findAlertById(new AlertId(7));

        self::assertSame(7, $alert?->id->toInt());
        self::assertSame('/v1/alerts/7', $this->requests[0][0], 'the detail call addresses the alert directly');
    }

    /**
     * The regression worth guarding. CrowdSec prunes alerts on its own schedule,
     * so a link that was valid a minute ago can point at nothing. That has to
     * read as "gone" and render an empty detail page, not as an outage.
     */
    #[Test]
    public function anAlertThatNoLongerExistsYieldsNull(): void
    {
        $repository = $this->repositoryAnswering([], new LapiNotFoundException('404'));

        self::assertNull($repository->findAlertById(new AlertId(999)));
    }
}
