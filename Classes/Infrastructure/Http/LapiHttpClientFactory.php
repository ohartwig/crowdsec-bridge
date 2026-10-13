<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * The HTTP client for the CrowdSec LAPI, with a CA of its own when one is given.
 *
 * The platform moves the central LAPI to TLS with a certificate from its own
 * workload CA. That CA must be trusted for exactly this connection and nowhere
 * else: TYPO3's HTTP `verify` setting is global and would apply to every
 * outbound request of the installation, and PHP's curl ignores SSL_CERT_FILE /
 * SSL_CERT_DIR (measured in the runtime image) - its only other lever,
 * curl.cainfo, replaces the system store for every request.
 *
 * So: CROWDSEC_LAPI_CA_FILE set -> a client that verifies against that file,
 * with TYPO3's other HTTP options. Not set -> TYPO3's own client, unchanged.
 * An unreadable file is not caught here: the request then fails with a TLS
 * error the module reports as "LAPI unavailable", which is louder than a
 * silent fallback to a trust store that cannot verify the LAPI anyway.
 */
final readonly class LapiHttpClientFactory
{
    public const ENV_CA_FILE = 'CROWDSEC_LAPI_CA_FILE';

    public function __construct(
        private GuzzleClientFactory $typo3ClientFactory,
    ) {}

    public function create(): ClientInterface
    {
        $caFile = getenv(self::ENV_CA_FILE);
        if (!\is_string($caFile) || '' === $caFile) {
            $client = $this->typo3ClientFactory->getClient();
            // TYPO3 declares Guzzle's own interface; the module needs PSR-18,
            // which every Guzzle client implements.
            if (!$client instanceof ClientInterface) {
                throw new \LogicException('TYPO3\'s HTTP client does not implement PSR-18.');
            }

            return $client;
        }

        $options = $GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? [];
        // allowed_hosts is TYPO3's middleware configuration and handler a
        // TYPO3-side stack definition; neither is a Guzzle option.
        unset($options['allowed_hosts'], $options['handler']);
        $options['verify'] = $caFile;

        return new Client($options);
    }
}
