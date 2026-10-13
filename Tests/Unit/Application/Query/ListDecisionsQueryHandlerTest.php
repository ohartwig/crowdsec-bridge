<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Query;

use Koh\CrowdsecBridge\Application\Query\ListDecisionsQuery;
use Koh\CrowdsecBridge\Application\Query\ListDecisionsQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use PHPUnit\Framework\TestCase;

final class ListDecisionsQueryHandlerTest extends TestCase
{
    public function testEmptyRepositoryReturnsEmptyView(): void
    {
        $handler = new ListDecisionsQueryHandler(
            $this->repositoryReturning([]),
            $this->fixedClock('2026-05-20T12:00:00+00:00'),
        );

        $view = $handler->handle(new ListDecisionsQuery());

        self::assertTrue($view->isEmpty());
        self::assertSame(1, $view->pagination->currentPage);
        self::assertFalse(false === $view->appliedFilter->isEmpty());
    }

    public function testPopulatedRepositoryYieldsViews(): void
    {
        $decisions = $this->buildDecisions(50);
        $handler = new ListDecisionsQueryHandler(
            $this->repositoryReturning($decisions),
            $this->fixedClock('2026-05-20T12:00:00+00:00'),
        );

        $view = $handler->handle(new ListDecisionsQuery(page: 1));

        self::assertCount(50, $view->decisions);
        self::assertSame('203.0.113.1', $view->decisions[0]->value);
    }

    public function testFilterIsForwardedToRepository(): void
    {
        $repository = new class implements DecisionRepositoryInterface {
            public ?FilterSelection $capturedFilter = null;

            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                $this->capturedFilter = $filter;

                return [];
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return null;
            }

            public function findById(DecisionId $id): ?Decision
            {
                return null;
            }
        };

        $handler = new ListDecisionsQueryHandler(
            $repository,
            $this->fixedClock('2026-05-20T12:00:00+00:00'),
        );

        $handler->handle(new ListDecisionsQuery(
            scope: DecisionScope::Range,
            type: DecisionType::Ban,
            origin: 'typo3',
        ));

        self::assertNotNull($repository->capturedFilter);
        self::assertSame(DecisionScope::Range, $repository->capturedFilter->scope);
        self::assertSame(DecisionType::Ban, $repository->capturedFilter->type);
        self::assertSame('typo3', $repository->capturedFilter->origin);
    }

    public function testNegativePageIsClampedToOne(): void
    {
        $handler = new ListDecisionsQueryHandler(
            $this->repositoryReturning([]),
            $this->fixedClock('2026-05-20T12:00:00+00:00'),
        );

        $view = $handler->handle(new ListDecisionsQuery(page: 0));

        self::assertSame(1, $view->pagination->currentPage);
    }

    /**
     * @return list<Decision>
     */
    private function buildDecisions(int $count): array
    {
        $decisions = [];
        for ($i = 1; $i <= $count; ++$i) {
            $decisions[] = new Decision(
                id: new DecisionId($i),
                scope: DecisionScope::Ip,
                type: DecisionType::Ban,
                value: '203.0.113.' . $i,
                originalDuration: Duration::fromHours(24),
                expiresAt: new \DateTimeImmutable('2026-05-21T12:00:00+00:00'),
                origin: 'cscli',
                scenario: 'manual',
            );
        }

        return $decisions;
    }

    /**
     * @param list<Decision> $decisions
     */
    private function repositoryReturning(array $decisions): DecisionRepositoryInterface
    {
        return new class ($decisions) implements DecisionRepositoryInterface {
            /** @param list<Decision> $decisions */
            public function __construct(private array $decisions) {}

            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                return $this->decisions;
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return $this->decisions[0] ?? null;
            }

            public function findById(DecisionId $id): ?Decision
            {
                return $this->decisions[0] ?? null;
            }
        };
    }

    private function fixedClock(string $iso): ClockInterface
    {
        return new class ($iso) implements ClockInterface {
            public function __construct(private readonly string $iso) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->iso);
            }
        };
    }
}
