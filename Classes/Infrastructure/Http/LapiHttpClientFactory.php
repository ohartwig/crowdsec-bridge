<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Client\ClientInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The HTTP client for the CrowdSec LAPI: TYPO3's HTTP options, a bounded
 * timeout of the module's own, and a CA of its own when one is given.
 *
 * Timeout. TYPO3 ships `HTTP.timeout = 0`, unlimited, and no installation
 * examined sets it. Every module screen makes two or three LAPI calls, so a
 * LAPI that accepts the connection and then stalls held the PHP worker until
 * `max_execution_time` (AUD-002-F01). The extension setting `lapiTimeout` had
 * promised a bound since 0.1 and was never read; it is read here and applied
 * to both the connect and the read phase of every LAPI request.
 *
 * TLS. The platform moves the central LAPI to TLS with a certificate from its
 * own workload CA. That CA must be trusted for exactly this connection and
 * nowhere else: TYPO3's HTTP `verify` setting is global and would apply to
 * every outbound request of the installation, and PHP's curl ignores
 * SSL_CERT_FILE / SSL_CERT_DIR (measured in the runtime image) - its only other
 * lever, curl.cainfo, replaces the system store for every request. So:
 * CROWDSEC_LAPI_CA_FILE set -> verify against that file. An unreadable file is
 * not caught here: the request then fails with a TLS error the module reports
 * as "LAPI unavailable", which is louder than a silent fallback to a trust
 * store that cannot verify the LAPI anyway.
 *
 * The client is built from `$GLOBALS['TYPO3_CONF_VARS']['HTTP']` the way
 * TYPO3's own GuzzleClientFactory builds it - `verify` normalised, the
 * `handler` middleware array pushed onto a handler stack - because a Guzzle
 * client's options are fixed at construction and TYPO3's factory offers no
 * way to add one. Only the per-context `allowed_hosts` middleware is left
 * out: it is keyed by a TYPO3 request context the module does not have.
 */
final readonly class LapiHttpClientFactory
{
    public const ENV_CA_FILE = 'CROWDSEC_LAPI_CA_FILE';

    public const EXTENSION_KEY = 'crowdsec_bridge';
    public const TIMEOUT_KEY = 'lapiTimeout';

    /** What ext_conf_template.txt promises when the setting is absent or unusable. */
    public const TIMEOUT_DEFAULT = 5;
    public const TIMEOUT_MIN = 1;
    public const TIMEOUT_MAX = 120;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function create(): ClientInterface
    {
        $options = $this->typo3HttpOptions();

        $timeout = $this->timeoutSeconds();
        $options['timeout'] = $timeout;
        // TYPO3's connect_timeout (default 10) stays when it is the tighter
        // bound; a 3 s module timeout must not wait 10 s for a SYN-ACK.
        $connectTimeout = $options['connect_timeout'] ?? $timeout;
        $options['connect_timeout'] = \is_int($connectTimeout) || \is_float($connectTimeout)
            ? min((int) $connectTimeout, $timeout)
            : $timeout;

        $caFile = getenv(self::ENV_CA_FILE);
        if (\is_string($caFile) && '' !== $caFile) {
            $options['verify'] = $caFile;
        }

        return new Client($options);
    }

    /**
     * `lapiTimeout` from the extension configuration, clamped to a usable
     * range. Anything that is not a positive integer - the setting missing,
     * the extension not configured yet, an empty string, `0` - is the default:
     * a timeout the operator did not set is still a timeout.
     */
    public function timeoutSeconds(): int
    {
        try {
            $raw = $this->extensionConfiguration->get(self::EXTENSION_KEY, self::TIMEOUT_KEY);
        } catch (\Throwable) {
            return self::TIMEOUT_DEFAULT;
        }

        if (\is_int($raw)) {
            $seconds = $raw;
        } elseif (\is_string($raw) && ctype_digit($raw)) {
            $seconds = (int) $raw;
        } else {
            return self::TIMEOUT_DEFAULT;
        }

        if ($seconds < self::TIMEOUT_MIN) {
            return self::TIMEOUT_DEFAULT;
        }

        return min($seconds, self::TIMEOUT_MAX);
    }

    /**
     * @return array<string, mixed>
     */
    private function typo3HttpOptions(): array
    {
        $options = $GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? [];
        if (!\is_array($options)) {
            $options = [];
        }

        // As TYPO3 does: a "true"/"false" string from the settings becomes a
        // boolean, a path to a CA bundle stays a path.
        if (isset($options['verify'])) {
            $options['verify'] = filter_var($options['verify'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
                ?? $options['verify'];
        }

        $stack = ($options['handler'] ?? null) instanceof HandlerStack ? $options['handler'] : HandlerStack::create();
        if (\is_array($options['handler'] ?? null)) {
            foreach ($options['handler'] as $name => $handler) {
                $stack->push($handler, (string) $name);
            }
        }
        $options['handler'] = $stack;

        // allowed_hosts is TYPO3's per-context middleware configuration, not
        // a Guzzle option.
        unset($options['allowed_hosts']);

        return $options;
    }
}
