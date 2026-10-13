<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertFilterSelection;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use PHPUnit\Framework\TestCase;

final class AlertFilterSelectionTest extends TestCase
{
    public function testIsEmptyByDefault(): void
    {
        self::assertTrue(new AlertFilterSelection()->isEmpty());
    }

    public function testScopeOnlyNotEmpty(): void
    {
        $filter = new AlertFilterSelection(scope: DecisionScope::Ip);
        self::assertFalse($filter->isEmpty());
        self::assertSame(['scope' => 'Ip'], $filter->toLapiQueryParams());
    }

    public function testScenarioFilterNeverDelegatedToLapi(): void
    {
        $filter = new AlertFilterSelection(scenarioContains: 'ssh');
        self::assertFalse($filter->isEmpty());
        self::assertSame([], $filter->toLapiQueryParams(), 'scenario filter is client-side only');
    }

    public function testMatchesScenarioCaseInsensitive(): void
    {
        $filter = new AlertFilterSelection(scenarioContains: 'ssh');

        self::assertTrue($filter->matchesScenario('crowdsecurity/ssh-bf'));
        self::assertTrue($filter->matchesScenario('CROWDSECURITY/SSH-SLOWLORIS'));
        self::assertFalse($filter->matchesScenario('crowdsecurity/http-bad-ua'));
    }

    public function testEmptyScenarioContainsMatchesAnything(): void
    {
        $filter = new AlertFilterSelection(scenarioContains: '');
        self::assertTrue($filter->matchesScenario('anything'));
        self::assertTrue($filter->isEmpty());
    }
}
