<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Audit;

use Koh\CrowdsecBridge\Infrastructure\Audit\LogLineSanitizer;
use PHPUnit\Framework\TestCase;

final class LogLineSanitizerTest extends TestCase
{
    public function testStripsNewlinesAndCarriageReturns(): void
    {
        $forged = "abuse\n[INFO] uid:1 deleted decision #99 — status=success";
        $cleaned = LogLineSanitizer::clean($forged);

        // The security property is that the forged content cannot start a new log
        // line — splitting on newline/CR must yield a single line. The forged
        // payload itself is preserved (inert) so investigators still see what was
        // submitted.
        self::assertStringNotContainsString("\n", $cleaned);
        self::assertStringNotContainsString("\r", $cleaned);
        $lines = preg_split('/[\r\n]/', $cleaned);
        self::assertNotFalse($lines);
        self::assertCount(1, $lines);
        self::assertStringContainsString('abuse', $cleaned);
        self::assertStringContainsString('[INFO] uid:1', $cleaned, 'forged tokens are preserved inline, not deleted');
    }

    public function testStripsTabsAndOtherControlChars(): void
    {
        $cleaned = LogLineSanitizer::clean("foo\tbar\x00baz\x1fqux");
        self::assertSame('foo bar baz qux', $cleaned);
    }

    public function testPreservesNormalCharactersIncludingUnicode(): void
    {
        $cleaned = LogLineSanitizer::clean('Portscanner 203.0.113.42 — fünf Sekunden 🚨');
        self::assertSame('Portscanner 203.0.113.42 — fünf Sekunden 🚨', $cleaned);
    }

    public function testTruncatesLongValues(): void
    {
        $long = str_repeat('x', 1500);
        $cleaned = LogLineSanitizer::clean($long);
        self::assertSame(1000, mb_strlen($cleaned));
        self::assertStringEndsWith('…', $cleaned);
    }

    public function testNullBecomesEmptyString(): void
    {
        self::assertSame('', LogLineSanitizer::clean(null));
    }
}
