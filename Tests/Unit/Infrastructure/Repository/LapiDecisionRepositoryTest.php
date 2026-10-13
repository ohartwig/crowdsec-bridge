<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiDecisionRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The read side of the CrowdSec integration. It is what the backend module
 * lists, and what an operator checks before lifting a ban.
 *
 * The real parser is used rather than a double: the repository's whole job is
 * to shape a query, hand the answer to the parser and pick from the result, so
 * substituting the parser would leave almost nothing under test.
 */
final class LapiDecisionRepositoryTest extends TestCase
{
    /** @var list<array{string, array<string, string>}> */
    private array $requests = [];

    /**
     * @param list<array<string, mixed>> $body
     */
    private function repositoryAnswering(array $body): LapiDecisionRepository
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('get')->willReturnCallback(
            function (string $path, array $query) use ($body): array {
                $this->requests[] = [$path, $query];

                return $body;
            },
        );

        return new LapiDecisionRepository($client, new LapiResponseParser());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function decisionBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'scope' => 'Ip',
            'type' => 'ban',
            'value' => '203.0.113.42',
            'duration' => '24h',
            'origin' => 'cscli',
            'scenario' => 'manual test',
            'until' => '2026-05-22T12:00:00Z',
        ], $overrides);
    }

    /**
     * Paging is done by LAPI, so limit and offset have to reach it. Losing them
     * would silently return the first page for every page the operator clicks.
     */
    #[Test]
    public function theFilterAndThePageReachTheQuery(): void
    {
        $repository = $this->repositoryAnswering([$this->decisionBody()]);

        $repository->findActive(
            new FilterSelection(DecisionScope::Ip, DecisionType::Ban, 'cscli'),
            new Pagination(2),
        );

        [$path, $query] = $this->requests[0];
        self::assertSame('/v1/decisions', $path);
        self::assertSame('Ip', $query['scope']);
        self::assertSame('ban', $query['type']);
        self::assertSame('cscli', $query['origins'], 'the decision filter spells the origin plural');
        self::assertSame('50', $query['limit'], 'LAPI takes the query as strings');
        self::assertSame('50', $query['offset'], 'page 2 starts after the first 50');
    }

    #[Test]
    public function anUnfilteredQueryCarriesOnlyThePage(): void
    {
        $this->repositoryAnswering([])->findActive(new FilterSelection(), new Pagination(1));

        [, $query] = $this->requests[0];
        self::assertSame(['limit' => '50', 'offset' => '0'], $query);
    }

    #[Test]
    public function activeDecisionsAreParsed(): void
    {
        $decisions = $this->repositoryAnswering([
            $this->decisionBody(['id' => 1]),
            $this->decisionBody(['id' => 2]),
        ])->findActive(new FilterSelection(), new Pagination(1));

        self::assertSame([1, 2], array_map(static fn($d): int => $d->id->toInt(), $decisions));
    }

    /**
     * LAPI answers "nothing found" with an empty array, which the repository has
     * to translate to null for the parser — an empty array means something
     * different to it than an absent body.
     */
    #[Test]
    public function anEmptyAnswerYieldsNoDecisions(): void
    {
        self::assertSame([], $this->repositoryAnswering([])->findActive(new FilterSelection(), new Pagination(1)));
        self::assertNull($this->repositoryAnswering([])->findByIp(new IpAddress('203.0.113.42')));
        self::assertNull($this->repositoryAnswering([])->findById(new DecisionId(42)));
    }

    #[Test]
    public function aDecisionIsFoundByIp(): void
    {
        $repository = $this->repositoryAnswering([$this->decisionBody()]);

        $decision = $repository->findByIp(new IpAddress('203.0.113.42'));

        self::assertSame(42, $decision?->id->toInt());
        self::assertSame(['ip' => '203.0.113.42'], $this->requests[0][1]);
    }

    /**
     * An address can carry several decisions; the first is the one the quick
     * check reports.
     */
    #[Test]
    public function theFirstDecisionForAnAddressWins(): void
    {
        $decision = $this->repositoryAnswering([
            $this->decisionBody(['id' => 7]),
            $this->decisionBody(['id' => 8]),
        ])->findByIp(new IpAddress('203.0.113.42'));

        self::assertSame(7, $decision?->id->toInt());
    }

    #[Test]
    public function aDecisionIsFoundById(): void
    {
        $repository = $this->repositoryAnswering([$this->decisionBody(['id' => 42])]);

        self::assertSame(42, $repository->findById(new DecisionId(42))?->id->toInt());
        self::assertSame(['id' => '42'], $this->requests[0][1]);
    }

    /**
     * The regression worth guarding. LAPI's `id` query parameter is not reliably
     * honoured — it can answer with a different decision — so the repository
     * checks the id again on the way out. Without that check the module would
     * show, and then delete, a decision the operator never selected.
     */
    #[Test]
    public function aDecisionWithADifferentIdIsNotAccepted(): void
    {
        $repository = $this->repositoryAnswering([$this->decisionBody(['id' => 99])]);

        self::assertNull($repository->findById(new DecisionId(42)));
    }

    #[Test]
    public function theMatchingDecisionIsPickedOutOfSeveral(): void
    {
        $repository = $this->repositoryAnswering([
            $this->decisionBody(['id' => 7]),
            $this->decisionBody(['id' => 42, 'value' => '198.51.100.9']),
            $this->decisionBody(['id' => 8]),
        ]);

        $decision = $repository->findById(new DecisionId(42));

        self::assertNotNull($decision);
        self::assertSame(42, $decision->id->toInt());
        self::assertSame('198.51.100.9', $decision->value);
    }
}
