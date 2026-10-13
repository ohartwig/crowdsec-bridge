<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

/**
 * Resolves the CrowdSec machine (watcher) account the bridge logs in with, in
 * the same three-step order Constitution principle IV lays down for the bouncer
 * key — a second secret must not get a second, laxer rule:
 *
 * 1. Docker secret files `/run/secrets/crowdsec_machine_id` and
 *    `/run/secrets/crowdsec_machine_password`
 * 2. Environment variables `CROWDSEC_LAPI_MACHINE_ID` /
 *    `CROWDSEC_LAPI_MACHINE_PASSWORD`
 * 3. `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge']['machineId']`
 *    and `[...]['machinePassword']` — local development only
 *
 * Unlike LapiKeyResolver this returns null instead of throwing when nothing is
 * configured. An installation that only ever wanted the decision list has no
 * machine account, and that is a supported state: the read screen keeps working
 * and only the watcher-only features report that they have no identity to use.
 */
final readonly class LapiMachineCredentialsResolver
{
    private const DEFAULT_ID_FILE = '/run/secrets/crowdsec_machine_id';
    private const DEFAULT_PASSWORD_FILE = '/run/secrets/crowdsec_machine_password';
    private const ENV_ID = 'CROWDSEC_LAPI_MACHINE_ID';
    private const ENV_PASSWORD = 'CROWDSEC_LAPI_MACHINE_PASSWORD';
    private const CONF_ID_PATH = ['EXTENSIONS', 'crowdsec_bridge', 'machineId'];
    private const CONF_PASSWORD_PATH = ['EXTENSIONS', 'crowdsec_bridge', 'machinePassword'];

    public function __construct(
        private string $machineIdFilePath = self::DEFAULT_ID_FILE,
        private string $passwordFilePath = self::DEFAULT_PASSWORD_FILE,
    ) {}

    public function resolve(): ?LapiMachineCredentials
    {
        $machineId = $this->firstNonEmpty(
            $this->fromFile($this->machineIdFilePath),
            $this->fromEnv(self::ENV_ID),
            $this->fromGlobals(self::CONF_ID_PATH),
        );
        $password = $this->firstNonEmpty(
            $this->fromFile($this->passwordFilePath),
            $this->fromEnv(self::ENV_PASSWORD),
            $this->fromGlobals(self::CONF_PASSWORD_PATH),
        );

        // Half a credential is a configuration mistake, not a second mode: a
        // login attempt with an empty password would be answered with 403 and
        // read like a wrong password rather than like a missing setting.
        if (null === $machineId || null === $password) {
            return null;
        }

        return new LapiMachineCredentials($machineId, $password);
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function fromFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        $trimmed = trim($contents);

        return '' === $trimmed ? null : $trimmed;
    }

    private function fromEnv(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param list<string> $path
     */
    private function fromGlobals(array $path): ?string
    {
        $value = $GLOBALS['TYPO3_CONF_VARS'] ?? null;

        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
