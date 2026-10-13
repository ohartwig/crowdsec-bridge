<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Support;

use Koh\CrowdsecBridge\Presentation\Backend\Support\TranslatorPlaceholders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The labels are written with {0}, {1}; the backend showed "Letzte Prüfung: {0}"
 * because f:translate only understands %s. These pin the rewrite both the
 * templates and the flash messages go through.
 */
final class TranslatorPlaceholdersTest extends TestCase
{
    #[Test]
    public function sequentialPlaceholdersAreFilled(): void
    {
        self::assertSame(
            'Connected — CrowdSec v1.8.1, Bouncer: caddy',
            TranslatorPlaceholders::fill('Connected — CrowdSec {0}, Bouncer: {1}', ['v1.8.1', 'caddy']),
        );
    }

    /**
     * "remaining {1}h {2}m" is given [days, hours, minutes]. A plain %s rewrite
     * would print the days where the hours belong.
     */
    #[Test]
    public function placeholdersArePositionalNotSequential(): void
    {
        self::assertSame('noch 3h 20m', TranslatorPlaceholders::fill('noch {1}h {2}m', [0, 3, 20]));
        self::assertSame('noch 20m', TranslatorPlaceholders::fill('noch {2}m', [0, 0, 20]));
    }

    #[Test]
    public function keyedFluidArraysAreTakenInOrder(): void
    {
        self::assertSame('Last check: 2026-10-13 11:38:06', TranslatorPlaceholders::fill('Last check: {0}', [0 => '2026-10-13 11:38:06']));
    }

    #[Test]
    public function aTemplateWithoutArgumentsIsLeftAlone(): void
    {
        self::assertSame('Banned {0}.', TranslatorPlaceholders::fill('Banned {0}.', []));
        self::assertSame('100% sure', TranslatorPlaceholders::fill('100% sure', []));
    }

    #[Test]
    public function sprintfSyntaxStillWorks(): void
    {
        self::assertSame('Banned 203.0.113.42.', TranslatorPlaceholders::fill('Banned %s.', ['203.0.113.42']));
    }

    #[Test]
    public function tooFewArgumentsShowTheLabelInsteadOfFailing(): void
    {
        self::assertSame('{0} and {1}', TranslatorPlaceholders::fill('{0} and {1}', ['only one']));
    }
}
