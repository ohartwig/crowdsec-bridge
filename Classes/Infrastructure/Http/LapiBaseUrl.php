<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

/**
 * Where the LAPI base URL comes from, in order:
 *
 * 1. the environment variable CROWDSEC_LAPI_BASE_URL - the deployment's word,
 *    set by the chart or the compose file;
 * 2. the extension setting `lapiBaseUrl` - the administrator's word, from the
 *    settings module;
 * 3. the built-in default, `http://crowdsec:8080`.
 *
 * The environment wins because it is what the deployer controls and reviews;
 * the setting is for an installation that has no such variable. Until 0.10
 * the setting existed and was never read (AUD-002-F01): an administrator who
 * switched it to `https://` changed nothing while the settings said otherwise.
 *
 * Steps 2 and 3 are resolved here, at container build time, into the
 * parameter that `%env(default:…:CROWDSEC_LAPI_BASE_URL)%` falls back on
 * (Configuration/Services.php); step 1 is the env() processor's own work.
 */
final class LapiBaseUrl
{
    public const DEFAULT = 'http://crowdsec:8080';

    public const EXTENSION_KEY = 'crowdsec_bridge';
    public const SETTING_KEY = 'lapiBaseUrl';

    /**
     * The setting as a usable base URL, or null when it is not one: an absolute
     * http or https URL with a host, nothing else. A value that fails here is
     * treated as unset rather than guessed at - a base URL the module cannot
     * parse would only produce a different unreachable endpoint.
     */
    public static function fromSetting(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }
        $candidate = trim($raw);
        if ('' === $candidate) {
            return null;
        }
        if (false === filter_var($candidate, \FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($candidate);
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if ('' === (string) ($parts['host'] ?? '')) {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return null;
        }

        return rtrim($candidate, '/');
    }

    /**
     * The setting when it is usable, the default when it is not.
     */
    public static function fromSettingOrDefault(mixed $raw): string
    {
        return self::fromSetting($raw) ?? self::DEFAULT;
    }
}
