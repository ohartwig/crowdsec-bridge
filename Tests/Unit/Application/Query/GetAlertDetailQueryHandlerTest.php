<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Query;

use Koh\CrowdsecBridge\Application\Query\GetAlertDetailQuery;
use Koh\CrowdsecBridge\Application\Query\GetAlertDetailQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\AlertRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Exception\AlertNotFoundException;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use PHPUnit\Framework\TestCase;

final class GetAlertDetailQueryHandlerTest extends TestCase
{
    public function testHappyPath(): void
    {
        $alert = $this->alert(42, 3);
        $handler = new GetAlertDetailQueryHandler($this->repoReturning($alert), $this->clock());

        $view = $handler->handle(new GetAlertDetailQuery(new AlertId(42)));

        self::assertSame(42, $view->id);
        self::assertCount(3, $view->decisions);
        self::assertSame(0, $view->decisionsOverflow);
    }

    public function testOverflowCapsDecisionsAt50(): void
    {
        $alert = $this->alert(7, 75);
        $handler = new GetAlertDetailQueryHandler($this->repoReturning($alert), $this->clock());

        $view = $handler->handle(new GetAlertDetailQuery(new AlertId(7)));

        self::assertCount(50, $view->decisions);
        self::assertSame(25, $view->decisionsOverflow);
    }

    public function testNullRepositoryReturnThrows(): void
    {
        $handler = new GetAlertDetailQueryHandler($this->repoReturning(null), $this->clock());

        $this->expectException(AlertNotFoundException::class);
        $handler->handle(new GetAlertDetailQuery(new AlertId(999)));
    }

    private function alert(int $id, int $decisionCount): Alert
    {
        $decisions = [];
        for ($i = 1; $i <= $decisionCount; ++$i) {
            $decisions[] = new Decision(
                id: new DecisionId($i),
                scope: DecisionScope::Ip,
                type: DecisionType::Ban,
                value: '203.0.113.' . $i,
                originalDuration: Duration::fromHours(1),
                expiresAt: new \DateTimeImmutable('2026-05-21T12:00:00+00:00'),
                origin: 'crowdsec',
                scenario: 'test',
            );
        }

        return new Alert(
            id: new AlertId($id),
            scenario: 'test-scenario',
            createdAt: new \DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            source: new AlertSource(scopeRaw: 'Ip', value: '203.0.113.1', scope: DecisionScope::Ip),
            decisionCount: $decisionCount,
            decisions: $decisions,
        );
    }

    private function repoReturning(?Alert $alert): AlertRepositoryInterface
    {
        return new class ($alert) implements AlertRepositoryInterface {
            public function __construct(private readonly ?Alert $alert) {}

            public function findAlerts(AlertFilterSelection $filter, Pagination $pagination): array
            {
                return [];
            }

            public function findAlertById(AlertId $id): ?Alert
            {
                return $this->alert;
            }
        };
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-05-20T12:00:00+00:00');
            }
        };
    }
}
