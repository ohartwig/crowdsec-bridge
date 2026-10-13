<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Query;

use Koh\CrowdsecBridge\Application\Query\ListAlertsQuery;
use Koh\CrowdsecBridge\Application\Query\ListAlertsQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\AlertRepositoryInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use PHPUnit\Framework\TestCase;

final class ListAlertsQueryHandlerTest extends TestCase
{
    public function testEmptyRepositoryReturnsEmptyView(): void
    {
        $handler = new ListAlertsQueryHandler($this->repositoryReturning([]));

        $view = $handler->handle(new ListAlertsQuery());

        self::assertTrue($view->isEmpty());
        self::assertFalse($view->hasFilter());
    }

    public function testPopulatedRepositoryYieldsViews(): void
    {
        $alerts = $this->buildAlerts(30);
        $handler = new ListAlertsQueryHandler($this->repositoryReturning($alerts));

        $view = $handler->handle(new ListAlertsQuery());

        self::assertCount(30, $view->alerts);
        self::assertSame(1, $view->alerts[0]->id);
        self::assertSame(2, $view->alerts[0]->decisionCount, 'decisionCount must propagate from VO');
    }

    public function testSubstringFilterAppliedClientSide(): void
    {
        $alerts = [
            $this->alert(1, 'crowdsecurity/ssh-bf'),
            $this->alert(2, 'crowdsecurity/http-bad-ua'),
            $this->alert(3, 'crowdsecurity/SSH-slowloris'),
        ];
        $handler = new ListAlertsQueryHandler($this->repositoryReturning($alerts));

        $view = $handler->handle(new ListAlertsQuery(scenarioContains: 'ssh'));

        self::assertCount(2, $view->alerts);
        self::assertSame(1, $view->alerts[0]->id);
        self::assertSame(3, $view->alerts[1]->id);
    }

    public function testFilterTypeForwardedToRepository(): void
    {
        $captured = new ListAlertsFilterCapture();
        $repository = new class ($captured) implements AlertRepositoryInterface {
            public function __construct(private readonly ListAlertsFilterCapture $captured) {}

            public function findAlerts(AlertFilterSelection $filter, Pagination $pagination): array
            {
                $this->captured->filter = $filter;

                return [];
            }

            public function findAlertById(AlertId $id): ?Alert
            {
                return null;
            }
        };

        $handler = new ListAlertsQueryHandler($repository);
        $handler->handle(new ListAlertsQuery(scope: DecisionScope::Range, scenarioContains: 'ssh'));

        self::assertNotNull($captured->filter);
        self::assertSame(DecisionScope::Range, $captured->filter->scope);
        self::assertSame('ssh', $captured->filter->scenarioContains);
    }

    /**
     * @return list<Alert>
     */
    private function buildAlerts(int $count): array
    {
        $alerts = [];
        for ($i = 1; $i <= $count; ++$i) {
            $alerts[] = $this->alert($i, 'crowdsecurity/test-' . $i);
        }

        return $alerts;
    }

    private function alert(int $id, string $scenario): Alert
    {
        return new Alert(
            id: new AlertId($id),
            scenario: $scenario,
            createdAt: new \DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            source: new AlertSource(scopeRaw: 'Ip', value: '203.0.113.' . $id, scope: DecisionScope::Ip),
            decisionCount: 2,
        );
    }

    /**
     * @param list<Alert> $alerts
     */
    private function repositoryReturning(array $alerts): AlertRepositoryInterface
    {
        return new class ($alerts) implements AlertRepositoryInterface {
            /** @param list<Alert> $alerts */
            public function __construct(private readonly array $alerts) {}

            public function findAlerts(AlertFilterSelection $filter, Pagination $pagination): array
            {
                return $this->alerts;
            }

            public function findAlertById(AlertId $id): ?Alert
            {
                return null;
            }
        };
    }
}

/**
 * Typed capture helper — replaces (object) ['filter' => null] so PHPStan can
 * track the property type. Test-only.
 */
final class ListAlertsFilterCapture
{
    public ?AlertFilterSelection $filter = null;
}
