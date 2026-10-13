<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

/**
 * Resolves the CrowdSec bouncer API key from the three permitted sources
 * (Constitution principle IV):
 *
 * 1. Docker secret file `/run/secrets/crowdsec_api_key`
 * 2. Environment variable `CROWDSEC_LAPI_KEY`
 * 3. `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']['lapiKey']` — dev only
 *
 * Throws when no source yields a non-empty value.
 */
final readonly class LapiKeyResolver
{
    private const DEFAULT_SECRET_FILE = '/run/secrets/crowdsec_api_key';
    private const ENV_VAR = 'CROWDSEC_LAPI_KEY';
    private const CONF_PATH = ['EXTENSIONS', 'crowdsec_bridge', 'lapiKey'];

    public function __construct(private string $secretFilePath = self::DEFAULT_SECRET_FILE) {}

    public function resolve(): string
    {
        if (is_readable($this->secretFilePath)) {
            $contents = file_get_contents($this->secretFilePath);
            if (false !== $contents) {
                $trimmed = trim($contents);
                if ('' !== $trimmed) {
                    return $trimmed;
                }
            }
        }

        $envKey = getenv(self::ENV_VAR);
        if (\is_string($envKey) && '' !== $envKey) {
            return $envKey;
        }

        $conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (\is_array($conf)) {
            $value = $conf;
            foreach (self::CONF_PATH as $key) {
                if (!\is_array($value) || !\array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        throw new \RuntimeException('CrowdSec LAPI key not configured. Provide one of: ' . self::DEFAULT_SECRET_FILE . ', $' . self::ENV_VAR . ', or $GLOBALS[TYPO3_CONF_VARS][EXTENSIONS][crowdsec_bridge][lapiKey].');
    }
}
