<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Support;

use Koh\CrowdsecBridge\Presentation\Backend\Support\AlertsListReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;

final class AlertsListReturnUrlTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('cases')]
    public function linksTheAlertsSubRouteWithWhitelistedArguments(array $params, array $expected): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->expects(self::once())->method('buildUriFromRoute')
            ->with('system_crowdsec.alerts', $expected)
            ->willReturn(new Uri('/typo3/module/system/crowdsec/alerts'));

        self::assertSame('/typo3/module/system/crowdsec/alerts', new AlertsListReturnUrl($uriBuilder)->build($params));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        yield 'nothing' => [[], []];
        yield 'all valid' => [
            ['returnPage' => '3', 'returnScope' => 'Ip', 'returnScenario' => 'ssh-bf'],
            ['page' => 3, 'scope' => 'Ip', 'scenario' => 'ssh-bf'],
        ];
        yield 'page zero, unknown scope, empty scenario' => [
            ['returnPage' => '0', 'returnScope' => 'Planet', 'returnScenario' => ''],
            [],
        ];
        yield 'non-numeric page, scenario too long' => [
            ['returnPage' => '2x', 'returnScenario' => str_repeat('a', 201)],
            [],
        ];
        yield 'free-form returnTo is ignored' => [
            ['returnTo' => 'javascript:alert(1)'],
            [],
        ];
    }
}
