<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use GuzzleHttp\Client;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * The LAPI client trusts a CA of its own only when one is configured, and
 * nothing else about TYPO3's HTTP handling changes when it is not.
 */
final class LapiHttpClientFactoryTest extends TestCase
{
    private mixed $httpBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->httpBackup = $GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = ['verify' => true, 'timeout' => 7, 'allowed_hosts' => []];
        putenv(LapiHttpClientFactory::ENV_CA_FILE);
    }

    protected function tearDown(): void
    {
        putenv(LapiHttpClientFactory::ENV_CA_FILE);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = $this->httpBackup;
        parent::tearDown();
    }

    #[Test]
    public function withoutTheVariableItIsTypo3sOwnClient(): void
    {
        $client = new LapiHttpClientFactory(new GuzzleClientFactory())->create();

        self::assertInstanceOf(Client::class, $client);
        self::assertTrue($client->getConfig('verify'));
    }

    #[Test]
    public function withTheVariableItVerifiesAgainstThatFileAndKeepsTheOtherOptions(): void
    {
        putenv(LapiHttpClientFactory::ENV_CA_FILE . '=/run/workload-ca/ca.crt');

        $client = new LapiHttpClientFactory(new GuzzleClientFactory())->create();

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('/run/workload-ca/ca.crt', $client->getConfig('verify'));
        self::assertSame(7, $client->getConfig('timeout'));
        self::assertNull($client->getConfig('allowed_hosts'));
    }

    #[Test]
    public function anEmptyVariableCountsAsNotSet(): void
    {
        putenv(LapiHttpClientFactory::ENV_CA_FILE . '=');

        $client = new LapiHttpClientFactory(new GuzzleClientFactory())->create();

        self::assertInstanceOf(Client::class, $client);
        self::assertTrue($client->getConfig('verify'));
    }
}
