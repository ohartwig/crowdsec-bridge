<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Functional\Presentation;

use Koh\CrowdsecBridge\Application\Command\AddDecisionCommandHandler;
use Koh\CrowdsecBridge\Application\Command\DeleteDecisionCommandHandler;
use Koh\CrowdsecBridge\Application\Contract\FormTokenValidatorInterface;
use Koh\CrowdsecBridge\Application\Query\CheckIpQueryHandler;
use Koh\CrowdsecBridge\Application\Query\GetAlertDetailQueryHandler;
use Koh\CrowdsecBridge\Application\Query\GetLapiStatusQueryHandler;
use Koh\CrowdsecBridge\Application\Query\ListAlertsQueryHandler;
use Koh\CrowdsecBridge\Application\Query\ListDecisionsQueryHandler;
use Koh\CrowdsecBridge\Domain\Contract\AlertRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiStatusRepositoryInterface;
use Koh\CrowdsecBridge\Domain\Contract\OperatorPermissionsResolverInterface;
use Koh\CrowdsecBridge\Domain\Contract\ReadAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\Contract\WriteAuditLoggerInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\AuditContext;
use Koh\CrowdsecBridge\Domain\ValueObject\Decision;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\FilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use Koh\CrowdsecBridge\Domain\ValueObject\LapiStatus;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use Koh\CrowdsecBridge\Domain\ValueObject\Pagination;
use Koh\CrowdsecBridge\Presentation\Backend\Controller\DecisionController;
use Koh\CrowdsecBridge\Presentation\Backend\Support\FlashMessenger;
use Koh\CrowdsecBridge\Presentation\Backend\Support\SubmitTokenStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Every screen of the module, rendered by TYPO3 itself.
 *
 * The module answered 500 three times on 2026-10-03, each time for a reason
 * no test here could see: a template in the wrong folder, a ViewHelper TYPO3
 * 14 does not have (f:uri.module), and f:translate without an extension in a
 * non-Extbase controller. The unit tests double ModuleTemplateFactory away
 * because it is final, so nothing ever rendered a template before production
 * did. This test renders each action through the real ModuleTemplate, with
 * data on every branch that has a loop or a condition, and fails on the first
 * Fluid error.
 */
final class BackendModuleRendersTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/crowdsec-bridge'];

    protected array $coreExtensionsToLoad = ['backend'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, bool, string}>
     */
    public static function screens(): iterable
    {
        yield 'decision list' => ['listAction', [], true, '198.51.100.0/24'];
        yield 'decision list, LAPI unreachable' => ['listAction', [], false, '203.0.113.1'];
        yield 'decision list, filtered' => ['listAction', ['scope' => 'Ip', 'type' => 'ban', 'origin' => 'cscli', 'page' => '1'], true, '203.0.113.1'];
        yield 'quick check, a match' => ['checkIpAction', ['ip' => '203.0.113.1'], true, '203.0.113.1'];
        yield 'quick check, no match' => ['checkIpAction', ['ip' => '198.51.100.7'], true, '198.51.100.7'];
        yield 'quick check, invalid input' => ['checkIpAction', ['ip' => 'not-an-ip'], true, 'not-an-ip'];
        yield 'new decision form' => ['newAction', [], true, 'name="durationPreset"'];
        yield 'alert list' => ['alertListAction', [], true, 'crowdsecurity/http-probing'];
        yield 'decision list, page 2 of many, filtered' => ['listAction', ['page' => '2', 'scope' => 'Ip'], true, 'page=3'];
        yield 'decision list, page 2: the link back' => ['listAction', ['page' => '2'], true, 'page=1'];
        yield 'alert list, page 2 of many, filtered' => ['alertListAction', ['page' => '2', 'scope' => 'Ip'], true, 'page=3'];
        yield 'alert list, page 2: the link back' => ['alertListAction', ['page' => '2'], true, 'page=1'];
        yield 'alert detail' => ['alertDetailAction', ['id' => '1', 'returnPage' => '2', 'returnScope' => 'Ip'], true, '203.0.113.1'];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('screens')]
    public function theScreenRenders(string $action, array $query, bool $reachable, string $marker): void
    {
        $controller = $this->controller($reachable);
        $request = $this->request($action, $query);
        $response = match ($action) {
            'listAction' => $controller->listAction($request),
            'checkIpAction' => $controller->checkIpAction($request),
            'newAction' => $controller->newAction($request),
            'alertListAction' => $controller->alertListAction($request),
            'alertDetailAction' => $controller->alertDetailAction($request),
            default => throw new \LogicException('no such action: ' . $action),
        };

        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString('Decisions', $body, 'the module navigation is missing - the layout did not render');
        self::assertStringNotContainsString('LLL:', $body, 'an untranslated label');
        // f:translate leaves the labels' {0} markers in place (2026-10-13).
        self::assertDoesNotMatchRegularExpression('/\{\d+\}/', $body, 'a label placeholder that was never filled');
        self::assertStringNotContainsString('f:uri.module', $body);
        self::assertStringContainsString($marker, $body, 'the screen rendered without its data');
        foreach (self::preselected($body) as $select => $options) {
            // selected="" is still selected: every option carried the attribute,
            // and the browser showed the last one (2026-10-13).
            self::assertLessThanOrEqual(1, \count($options), $select . ' preselects ' . implode(', ', $options));
        }
    }

    /**
     * @return iterable<string, array{string, array<string, string>, array<string, list<string>>}>
     */
    public static function preselections(): iterable
    {
        yield 'decision list, unfiltered' => ['listAction', [], ['scope' => [], 'type' => []]];
        yield 'decision list, filtered' => ['listAction', ['scope' => 'Range', 'type' => 'ban'], ['scope' => ['Range'], 'type' => ['ban']]];
        yield 'alert list, filtered' => ['alertListAction', ['scope' => 'Country'], ['scope' => ['Country']]];
        yield 'new decision form' => ['newAction', [], ['durationPreset' => ['24h']]];
    }

    /**
     * @param array<string, string>       $query
     * @param array<string, list<string>> $expected
     */
    #[Test]
    #[DataProvider('preselections')]
    public function theFiltersShowWhatWasApplied(string $action, array $query, array $expected): void
    {
        $controller = $this->controller(true);
        $request = $this->request($action, $query);
        $response = match ($action) {
            'listAction' => $controller->listAction($request),
            'alertListAction' => $controller->alertListAction($request),
            'newAction' => $controller->newAction($request),
            default => throw new \LogicException('no such action: ' . $action),
        };

        $preselected = self::preselected((string) $response->getBody());
        foreach ($expected as $select => $options) {
            self::assertSame($options, $preselected[$select] ?? [], $select);
        }
    }

    /**
     * The values of the preselected options, by the name of their select.
     *
     * @return array<string, list<string>>
     */
    private static function preselected(string $body): array
    {
        $document = new \DOMDocument();
        $document->loadHTML($body, \LIBXML_NOERROR | \LIBXML_NOWARNING);
        $out = [];
        foreach ($document->getElementsByTagName('select') as $select) {
            $name = $select->getAttribute('name');
            $out[$name] = [];
            foreach ($select->getElementsByTagName('option') as $option) {
                if ($option->hasAttribute('selected')) {
                    $out[$name][] = $option->getAttribute('value');
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $action, array $query): ServerRequestInterface
    {
        $routeKey = match ($action) {
            'checkIpAction' => 'system_crowdsec.check',
            'newAction' => 'system_crowdsec.new',
            'alertListAction' => 'system_crowdsec.alerts',
            'alertDetailAction' => 'system_crowdsec.alertDetail',
            default => 'system_crowdsec',
        };
        $route = $this->get(Router::class)->getRoute($routeKey);
        self::assertNotNull($route, $routeKey . ' is not registered');
        $server = ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/typo3/index.php', 'REQUEST_URI' => '/typo3/module/system/crowdsec'];

        return new ServerRequest('https://localhost/typo3/module/system/crowdsec', 'GET', 'php://input', [], $server)
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($server))
            ->withAttribute('route', $route)
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('system_crowdsec'));
    }

    private function controller(bool $reachable): DecisionController
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-03T12:00:00+00:00');
            }
        };
        $decisions = new class implements DecisionRepositoryInterface {
            public function findActive(FilterSelection $filter, Pagination $pagination): array
            {
                // A full page: the pagination block renders only then, and it is
                // where 0.9.6 broke (pagination.nextPage reached hasNextPage()).
                $page = [self::decision(1, DecisionScope::Ip, '203.0.113.1'), self::decision(2, DecisionScope::Range, '198.51.100.0/24')];
                for ($i = 3; $i <= 50; ++$i) {
                    $page[] = self::decision($i, DecisionScope::Ip, '192.0.2.' . $i);
                }

                return $page;
            }

            public function findByIp(IpAddress $ip): ?Decision
            {
                return '203.0.113.1' === $ip->value ? self::decision(1, DecisionScope::Ip, '203.0.113.1') : null;
            }

            public function findById(DecisionId $id): Decision
            {
                return self::decision($id->value, DecisionScope::Ip, '203.0.113.' . $id->value);
            }

            public static function decision(int $id, DecisionScope $scope, string $value): Decision
            {
                return new Decision(
                    id: new DecisionId($id),
                    scope: $scope,
                    type: DecisionType::Ban,
                    value: $value,
                    originalDuration: Duration::fromHours(24),
                    expiresAt: new \DateTimeImmutable('2026-10-04T12:00:00+00:00'),
                    origin: 'cscli',
                    scenario: 'crowdsecurity/ssh-bf',
                );
            }
        };
        $alerts = new class implements AlertRepositoryInterface {
            public function findAlerts(AlertFilterSelection $filter, Pagination $pagination): array
            {
                $page = [];
                for ($i = 1; $i <= 50; ++$i) {
                    $page[] = self::alert($i);
                }

                return $page;
            }

            public function findAlertById(AlertId $id): Alert
            {
                return self::alert($id->value);
            }

            public static function alert(int $id): Alert
            {
                return new Alert(
                    id: new AlertId($id),
                    scenario: 'crowdsecurity/http-probing',
                    createdAt: new \DateTimeImmutable('2026-10-03T10:00:00+00:00'),
                    source: new AlertSource(scopeRaw: 'Ip', value: '203.0.113.' . $id, scope: DecisionScope::Ip),
                    decisionCount: 1,
                );
            }
        };
        $status = new class ($reachable) implements LapiStatusRepositoryInterface {
            public function __construct(private readonly bool $reachable) {}

            public function findStatus(\DateTimeImmutable $checkedAt): LapiStatus
            {
                return $this->reachable
                    ? LapiStatus::reachable('v1.8.1', 'typo3-bridge', $checkedAt)
                    : LapiStatus::unreachable('timeout', $checkedAt);
            }
        };
        $permissions = new class implements OperatorPermissionsResolverInterface {
            public function resolve(): OperatorPermissions
            {
                return new OperatorPermissions(canRead: true, canWrite: true, canDeleteOwn: true, canDeleteForeign: true);
            }
        };
        $readAudit = new class implements ReadAuditLoggerInterface {
            public function logQuickCheck(AuditContext $context): void {}
        };

        return new DecisionController(
            $this->get(ModuleTemplateFactory::class),
            new ListDecisionsQueryHandler($decisions, $clock),
            new GetLapiStatusQueryHandler($status, $clock),
            new CheckIpQueryHandler($decisions, $readAudit, $clock),
            $this->get(AddDecisionCommandHandler::class),
            $this->get(DeleteDecisionCommandHandler::class),
            new ListAlertsQueryHandler($alerts),
            new GetAlertDetailQueryHandler($alerts, $clock),
            $this->get(FormTokenValidatorInterface::class),
            $this->get(WriteAuditLoggerInterface::class),
            $permissions,
            $decisions,
            $this->get(FlashMessenger::class),
            $this->get(SubmitTokenStore::class),
            $this->get(UriBuilder::class),
        );
    }
}
