<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Infrastructure\Http\LapiBaseUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The extension setting `lapiBaseUrl` is read since 0.11 (AUD-002-F01). What
 * it may contain is narrow on purpose: an absolute http(s) URL with a host.
 * Anything else is "not set", and the default applies.
 */
final class LapiBaseUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function settings(): iterable
    {
        yield 'the default' => ['http://crowdsec:8080', 'http://crowdsec:8080'];
        yield 'https with a path and a trailing slash' => ['https://lapi.crowdsec.svc/crowdsec/', 'https://lapi.crowdsec.svc/crowdsec'];
        yield 'surrounding whitespace is trimmed' => ["  http://lapi:8080 \n", 'http://lapi:8080'];
        yield 'an IPv6 literal' => ['http://[fd00::1]:8080', 'http://[fd00::1]:8080'];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'not a string' => [8080, null];
        yield 'no scheme' => ['crowdsec:8080', null];
        yield 'another scheme' => ['ftp://crowdsec:8080', null];
        yield 'a scheme only' => ['http://', null];
        yield 'credentials in the URL' => ['http://user:pass@crowdsec:8080', null];
        yield 'a query string' => ['http://crowdsec:8080/?x=1', null];
        yield 'a fragment' => ['http://crowdsec:8080/#top', null];
        yield 'free text' => ['the crowdsec container', null];
    }

    #[Test]
    #[DataProvider('settings')]
    public function onlyAnAbsoluteHttpUrlIsAccepted(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, LapiBaseUrl::fromSetting($raw));
    }

    #[Test]
    public function anUnusableSettingFallsBackToTheDefault(): void
    {
        self::assertSame(LapiBaseUrl::DEFAULT, LapiBaseUrl::fromSettingOrDefault('crowdsec:8080'));
        self::assertSame('https://lapi:8443', LapiBaseUrl::fromSettingOrDefault('https://lapi:8443/'));
    }
}
