<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

use Composer\InstalledVersions;

/**
 * The User-Agent every LAPI request carries.
 *
 * CrowdSec's watcher login splits the User-Agent on "/" and refuses anything
 * that is not exactly `name/version` with a 401 - after the password has been
 * checked, so the refusal looks like a wrong password. TYPO3's HTTP client sends
 * a bare "TYPO3" by default, which is exactly such a refusal. Seen against
 * crowdsec v1.8.1 on 2026-10-13: the LAPI accepted the password (it recorded
 * the machine's new IP), then logged "bad user agent 'TYPO3'" and answered 401.
 * The bouncer routes only warn about the same agent, which is why the decision
 * list kept working while the heartbeat and the alerts tab did not.
 *
 * The version also lands in `cscli machines list` and `cscli bouncers list`,
 * which is where an operator looks to tell which bridge release is talking.
 */
final class LapiUserAgent
{
    public const NAME = 'crowdsec-bridge';

    private const PACKAGE = 'koh/crowdsec-bridge';

    public static function header(): string
    {
        return self::NAME . '/' . self::packageVersion();
    }

    private static function packageVersion(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            $version = null;
        }

        // A branch install reports "dev-fix/x"; a second "/" would make the
        // agent unparseable again, and so would whitespace.
        $version = preg_replace('#[/\s]+#', '-', (string) $version) ?? '';

        return '' === $version ? 'unknown' : $version;
    }
}
