<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Support;

use Koh\CrowdsecBridge\Presentation\Backend\Support\RouteTokens;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;

final class RouteTokensTest extends TestCase
{
    #[Test]
    public function takesTheTokenFromTheBuiltUrlPerRoute(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnMap([
            ['system_crowdsec', [], UriBuilder::ABSOLUTE_PATH, new Uri('/typo3/module/system/crowdsec?token=aaa')],
            ['system_crowdsec.check', [], UriBuilder::ABSOLUTE_PATH, new Uri('/typo3/module/system/crowdsec/check?token=bbb&x=1')],
        ]);

        $tokens = new RouteTokens($uriBuilder)->for(['list' => 'system_crowdsec', 'check' => 'system_crowdsec.check']);

        self::assertSame(['list' => 'aaa', 'check' => 'bbb'], $tokens);
    }

    #[Test]
    public function aUrlWithoutTokenGivesAnEmptyString(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/module/system/crowdsec'));

        self::assertSame(['list' => ''], new RouteTokens($uriBuilder)->for(['list' => 'system_crowdsec']));
    }
}
