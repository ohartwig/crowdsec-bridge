<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Dto;

use Koh\CrowdsecBridge\Application\Dto\AlertView;
use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the alert list actually shows. Two things carry weight.
 *
 * The scope falls back twice: CrowdSec keeps adding scopes, so an unknown one
 * leaves the typed value null and only the raw string survives. Losing that
 * fallback would show an empty column for exactly the alerts that are new
 * enough to be interesting.
 *
 * And the truncation is multibyte-aware. Cutting mid-character produces broken
 * output in a table that routinely carries non-ASCII scenario names.
 */
final class AlertViewTest extends TestCase
{
    private function alert(AlertSource $source, string $scenario = 'crowdsecurity/ssh-bf'): Alert
    {
        return new Alert(
            new AlertId(7),
            $scenario,
            new \DateTimeImmutable('2026-07-30T08:00:00Z'),
            $source,
            3,
        );
    }

    #[Test]
    public function anAlertIsMappedOntoTheListColumns(): void
    {
        $view = AlertView::fromAlert($this->alert(
            new AlertSource('Ip', '203.0.113.42', DecisionScope::Ip),
        ));

        self::assertSame(7, $view->id);
        self::assertSame('crowdsecurity/ssh-bf', $view->scenario);
        self::assertSame('Ip', $view->sourceScope);
        self::assertSame('203.0.113.42', $view->sourceValue);
        self::assertSame(3, $view->decisionCount);
        self::assertSame('2026-07-30T08:00:00+00:00', $view->createdAt->format(\DateTimeInterface::ATOM));
    }

    /**
     * The regression worth guarding: CrowdSec introduces scopes over time, and
     * an unrecognised one leaves the typed value null on purpose — the raw
     * string is kept precisely so the column can still say something.
     */
    #[Test]
    public function anUnknownScopeFallsBackToTheRawValue(): void
    {
        $view = AlertView::fromAlert($this->alert(
            new AlertSource('AS', '64512', null),
        ));

        self::assertSame('AS', $view->sourceScope, 'the raw scope is what is left when the enum does not know it');
    }

    /**
     * Only when there is nothing at all does the column get a placeholder, so an
     * empty cell never looks like a rendering fault.
     */
    #[Test]
    public function anEmptyScopeBecomesAPlaceholder(): void
    {
        $view = AlertView::fromAlert($this->alert(new AlertSource('', '203.0.113.42', null)));

        self::assertSame('—', $view->sourceScope);
    }

    #[Test]
    public function shortValuesAreLeftAlone(): void
    {
        $view = AlertView::fromAlert($this->alert(
            new AlertSource('Ip', '203.0.113.42', DecisionScope::Ip),
            'short',
        ));

        self::assertSame('short', $view->scenarioTruncated);
        self::assertSame('203.0.113.42', $view->sourceValueTruncated);
    }

    #[Test]
    public function aLongScenarioIsTruncatedWithAnEllipsis(): void
    {
        $long = str_repeat('a', 100);

        $view = AlertView::fromAlert($this->alert(
            new AlertSource('Ip', '203.0.113.42', DecisionScope::Ip),
            $long,
        ));

        self::assertSame($long, $view->scenario, 'the full value stays available');
        self::assertSame(AlertView::TRUNCATE_AT, mb_strlen($view->scenarioTruncated));
        self::assertStringEndsWith('…', $view->scenarioTruncated);
    }

    /**
     * Exactly at the limit nothing is cut — an off-by-one here would put an
     * ellipsis on values that fit.
     */
    #[Test]
    public function aValueExactlyAtTheLimitIsNotTruncated(): void
    {
        $exact = str_repeat('b', AlertView::TRUNCATE_AT);

        $view = AlertView::fromAlert($this->alert(
            new AlertSource('Ip', '203.0.113.42', DecisionScope::Ip),
            $exact,
        ));

        self::assertSame($exact, $view->scenarioTruncated);
    }

    /**
     * Scenario names carry non-ASCII regularly. Cutting by bytes rather than by
     * characters would leave a broken sequence in the table.
     */
    #[Test]
    public function truncationCountsCharactersRatherThanBytes(): void
    {
        $multibyte = str_repeat('ü', 100);

        $view = AlertView::fromAlert($this->alert(
            new AlertSource('Ip', '203.0.113.42', DecisionScope::Ip),
            $multibyte,
        ));

        self::assertSame(AlertView::TRUNCATE_AT, mb_strlen($view->scenarioTruncated));
        self::assertSame($multibyte, $view->scenario);
        self::assertNotFalse(mb_check_encoding($view->scenarioTruncated, 'UTF-8'), 'no character may be cut in half');
    }
}
