<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Infrastructure\Http\LapiUserAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CrowdSec refuses a watcher login whose User-Agent does not split into exactly
 * two parts on "/". TYPO3's default "TYPO3" is such an agent.
 */
final class LapiUserAgentTest extends TestCase
{
    #[Test]
    public function theAgentIsExactlyNameSlashVersion(): void
    {
        $parts = explode('/', LapiUserAgent::header());

        self::assertCount(2, $parts);
        self::assertSame(LapiUserAgent::NAME, $parts[0]);
        self::assertNotSame('', $parts[1]);
        self::assertDoesNotMatchRegularExpression('/\s/', $parts[1]);
    }
}
