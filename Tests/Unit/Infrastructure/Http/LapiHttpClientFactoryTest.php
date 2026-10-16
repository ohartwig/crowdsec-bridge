<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiHttpClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Every LAPI request carries a bounded timeout, the LAPI client trusts a CA of
 * its own only when one is configured, and nothing else about TYPO3's HTTP
 * options changes on the way.
 *
 * The timeout is the regression worth guarding (AUD-002-F01): the setting was
 * documented from the first release and read by nothing, and TYPO3's default
 * for the read timeout is 0 - unlimited.
 *
 * The options are observed where they matter, at the transport: a middleware
 * registered through TYPO3's `HTTP.handler` array answers the request itself
 * and keeps the options Guzzle handed it. That is also the proof that TYPO3's
 * middleware configuration survives the trip.
 */
final class LapiHttpClientFactoryTest extends TestCase
{
    private mixed $httpBackup = null;

    /** @var array<string, mixed> */
    private array $capturedOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->httpBackup = $GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? null;
        $this->capturedOptions = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'verify' => true,
            'timeout' => 0,
            'connect_timeout' => 10,
            'allowed_hosts' => ['some-context' => ['example.org']],
            'handler' => [
                'capture' => function (callable $next): callable {
                    return function (RequestInterface $request, array $options): PromiseInterface {
                        $this->capturedOptions = $options;

                        return Create::promiseFor(new Response(204));
                    };
                },
            ],
        ];
        putenv(LapiHttpClientFactory::ENV_CA_FILE);
    }

    protected function tearDown(): void
    {
        putenv(LapiHttpClientFactory::ENV_CA_FILE);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = $this->httpBackup;
        parent::tearDown();
    }

    private function factoryWithSetting(mixed $lapiTimeout): LapiHttpClientFactory
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->expects(self::atLeastOnce())
            ->method('get')
            ->with(LapiHttpClientFactory::EXTENSION_KEY, LapiHttpClientFactory::TIMEOUT_KEY)
            ->willReturn($lapiTimeout);

        return new LapiHttpClientFactory($configuration);
    }

    private function factoryWithoutSetting(): LapiHttpClientFactory
    {
        $configuration = $this->createMock(ExtensionConfiguration::class);
        $configuration->method('get')
            ->willThrowException(new ExtensionConfigurationPathDoesNotExistException('lapiTimeout', 1509977699));

        return new LapiHttpClientFactory($configuration);
    }

    /**
     * Sends one request through the client and returns the options the
     * transport saw.
     *
     * @return array<string, mixed>
     */
    private function optionsSeenBy(LapiHttpClientFactory $factory): array
    {
        $response = $factory->create()->sendRequest(new Request('GET', 'http://crowdsec:8080/v1/heartbeat'));
        self::assertSame(204, $response->getStatusCode(), "TYPO3's HTTP.handler middleware is on the stack and answered");

        return $this->capturedOptions;
    }

    #[Test]
    public function theRequestCarriesTheConfiguredTimeoutWhereTypo3HadNone(): void
    {
        $options = $this->optionsSeenBy($this->factoryWithSetting('12'));

        self::assertSame(12, $options['timeout'], 'TYPO3 said 0, the setting says 12');
        self::assertSame(10, $options['connect_timeout'], "TYPO3's connect timeout is the tighter bound and stays");
        self::assertTrue($options['verify']);
        self::assertArrayNotHasKey('allowed_hosts', $options);
    }

    #[Test]
    public function aTightTimeoutBoundsTheConnectPhaseToo(): void
    {
        $options = $this->optionsSeenBy($this->factoryWithSetting(3));

        self::assertSame(3, $options['timeout']);
        self::assertSame(3, $options['connect_timeout'], 'a 3 s module timeout does not wait 10 s for a SYN-ACK');
    }

    #[Test]
    public function withoutTheSettingTheDocumentedDefaultApplies(): void
    {
        $options = $this->optionsSeenBy($this->factoryWithoutSetting());

        self::assertSame(LapiHttpClientFactory::TIMEOUT_DEFAULT, $options['timeout']);
        self::assertSame(LapiHttpClientFactory::TIMEOUT_DEFAULT, $options['connect_timeout']);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function unusableOrOutOfRangeSettings(): iterable
    {
        yield 'empty string' => ['', LapiHttpClientFactory::TIMEOUT_DEFAULT];
        yield 'zero is unlimited in Guzzle and therefore refused' => ['0', LapiHttpClientFactory::TIMEOUT_DEFAULT];
        yield 'negative' => [-5, LapiHttpClientFactory::TIMEOUT_DEFAULT];
        yield 'not a number' => ['soon', LapiHttpClientFactory::TIMEOUT_DEFAULT];
        yield 'above the ceiling' => ['3600', LapiHttpClientFactory::TIMEOUT_MAX];
        yield 'at the floor' => ['1', 1];
    }

    #[Test]
    #[DataProvider('unusableOrOutOfRangeSettings')]
    public function anUnusableSettingIsTheDefaultAndALargeOneIsCapped(mixed $raw, int $expected): void
    {
        self::assertSame($expected, $this->factoryWithSetting($raw)->timeoutSeconds());
    }

    #[Test]
    public function withTheVariableItVerifiesAgainstThatFileAndKeepsTheTimeout(): void
    {
        putenv(LapiHttpClientFactory::ENV_CA_FILE . '=/run/workload-ca/ca.crt');

        $options = $this->optionsSeenBy($this->factoryWithSetting('7'));

        self::assertSame('/run/workload-ca/ca.crt', $options['verify']);
        self::assertSame(7, $options['timeout']);
    }

    #[Test]
    public function anEmptyVariableCountsAsNotSet(): void
    {
        putenv(LapiHttpClientFactory::ENV_CA_FILE . '=');

        $options = $this->optionsSeenBy($this->factoryWithSetting('5'));

        self::assertTrue($options['verify']);
    }

    /**
     * TYPO3 stores `verify` as it was typed; its own factory normalises the
     * strings "true" and "false" and leaves a CA bundle path alone. So does
     * this one, or an installation with `verify = "false"` in its settings
     * would start verifying the LAPI against a file named "false".
     */
    #[Test]
    public function typo3sVerifyStringsAreNormalisedLikeTypo3Does(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'] = 'false';
        self::assertFalse($this->optionsSeenBy($this->factoryWithSetting('5'))['verify']);

        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'] = '/etc/ssl/certs/ca-bundle.crt';
        self::assertSame('/etc/ssl/certs/ca-bundle.crt', $this->optionsSeenBy($this->factoryWithSetting('5'))['verify']);
    }
}
