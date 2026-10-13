<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Infrastructure\Http\LapiKeyResolver;
use PHPUnit\Framework\TestCase;

final class LapiKeyResolverTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempFile = tempnam(sys_get_temp_dir(), 'lapikey_');
        putenv('CROWDSEC_LAPI_KEY');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        putenv('CROWDSEC_LAPI_KEY');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']);
        parent::tearDown();
    }

    public function testReadsFromSecretFileFirst(): void
    {
        file_put_contents($this->tempFile, "from-file\n");
        putenv('CROWDSEC_LAPI_KEY=from-env');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']['lapiKey'] = 'from-conf';

        $resolver = new LapiKeyResolver($this->tempFile);

        self::assertSame('from-file', $resolver->resolve());
    }

    public function testFallsBackToEnvWhenFileMissing(): void
    {
        putenv('CROWDSEC_LAPI_KEY=from-env');

        $resolver = new LapiKeyResolver('/nonexistent/path');

        self::assertSame('from-env', $resolver->resolve());
    }

    public function testFallsBackToConfWhenFileAndEnvMissing(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']['lapiKey'] = 'from-conf';

        $resolver = new LapiKeyResolver('/nonexistent/path');

        self::assertSame('from-conf', $resolver->resolve());
    }

    public function testThrowsWhenNothingConfigured(): void
    {
        $resolver = new LapiKeyResolver('/nonexistent/path');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/LAPI key not configured/');

        $resolver->resolve();
    }

    public function testIgnoresEmptyFile(): void
    {
        file_put_contents($this->tempFile, "  \n");
        putenv('CROWDSEC_LAPI_KEY=from-env');

        $resolver = new LapiKeyResolver($this->tempFile);

        self::assertSame('from-env', $resolver->resolve());
    }
}
