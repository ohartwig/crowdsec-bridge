<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDurationException;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validInputs(): iterable
    {
        yield 'simple hours' => ['24h'];
        yield 'days' => ['7d'];
        yield 'weeks' => ['2w'];
        yield 'composite' => ['1h30m'];
        yield 'go-form with seconds' => ['1h30m0s'];
        yield 'minutes only' => ['45m'];
        yield 'composite carrying a sub-second tail' => ['1s500ms'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'no unit' => ['24'];
        yield 'unknown unit' => ['24x'];
        yield 'plain text' => ['abc'];
        yield 'negative not allowed' => ['-1h'];
        yield 'with spaces' => ['24 h'];
    }

    /**
     * Refactor C7: a format-valid duration that resolves to zero seconds is rejected.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function zeroOrSubSecondInputs(): iterable
    {
        yield 'zero hours' => ['0h'];
        yield 'zero composite' => ['0h0m0s'];
        yield 'milliseconds only' => ['500ms'];
        yield 'nanoseconds only' => ['100ns'];
    }

    #[DataProvider('validInputs')]
    public function testAcceptsValidFormats(string $input): void
    {
        $duration = new Duration($input);
        self::assertSame($input, $duration->value);
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidFormats(string $input): void
    {
        $this->expectException(InvalidDurationException::class);
        new Duration($input);
    }

    public function testFromHours(): void
    {
        self::assertSame('24h', Duration::fromHours(24)->value);
        self::assertSame(86_400, Duration::fromHours(24)->toSeconds());
    }

    public function testFromDays(): void
    {
        self::assertSame('7d', Duration::fromDays(7)->value);
        self::assertSame(604_800, Duration::fromDays(7)->toSeconds());
    }

    public function testToSecondsForCompositeDuration(): void
    {
        self::assertSame(5_400, new Duration('1h30m')->toSeconds());
        self::assertSame(5_400, new Duration('1h30m0s')->toSeconds());
    }

    public function testCompositeWithSubSecondTailPreservesSecondPart(): void
    {
        // Sub-second units are still parsed (and ignored in toSeconds()), but the
        // duration as a whole must yield at least one second, otherwise C7 rejects it.
        self::assertSame(1, new Duration('1s500ms')->toSeconds());
    }

    #[DataProvider('zeroOrSubSecondInputs')]
    public function testRejectsZeroOrSubSecondDurations(string $input): void
    {
        $this->expectException(InvalidDurationException::class);
        new Duration($input);
    }
}
