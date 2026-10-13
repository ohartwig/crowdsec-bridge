<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Infrastructure\Http\LapiMachineCredentialsResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The machine account is a second secret, so it follows the same lookup order
 * Constitution principle IV lays down for the bouncer key: Docker secret file,
 * then environment, then TYPO3_CONF_VARS for local development only. A second
 * secret with a laxer rule would make the rule meaningless.
 */
final class LapiMachineCredentialsResolverTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        putenv('CROWDSEC_LAPI_MACHINE_ID');
        putenv('CROWDSEC_LAPI_MACHINE_PASSWORD');
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        putenv('CROWDSEC_LAPI_MACHINE_ID');
        putenv('CROWDSEC_LAPI_MACHINE_PASSWORD');
        unset($GLOBALS['TYPO3_CONF_VARS']);

        parent::tearDown();
    }

    #[Test]
    public function theSecretFilesWinOverEverythingElse(): void
    {
        putenv('CROWDSEC_LAPI_MACHINE_ID=from-env');
        putenv('CROWDSEC_LAPI_MACHINE_PASSWORD=from-env');

        $credentials = $this->resolver(
            $this->tempFile('from-file-id'),
            $this->tempFile('from-file-password'),
        )->resolve();

        self::assertNotNull($credentials);
        self::assertSame('from-file-id', $credentials->machineId);
        self::assertSame('from-file-password', $credentials->password);
    }

    #[Test]
    public function theEnvironmentIsUsedWhenNoSecretFileIsMounted(): void
    {
        putenv('CROWDSEC_LAPI_MACHINE_ID=env-id');
        putenv('CROWDSEC_LAPI_MACHINE_PASSWORD=env-password');

        $credentials = $this->resolver()->resolve();

        self::assertNotNull($credentials);
        self::assertSame('env-id', $credentials->machineId);
        self::assertSame('env-password', $credentials->password);
    }

    #[Test]
    public function theLocalDevelopmentSettingIsTheLastResort(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['crowdsec_bridge'] = [
            'machineId' => 'conf-id',
            'machinePassword' => 'conf-password',
        ];

        $credentials = $this->resolver()->resolve();

        self::assertNotNull($credentials);
        self::assertSame('conf-id', $credentials->machineId);
    }

    /**
     * No machine account is a supported state, not an error: an installation
     * that only wants the decision list never needs one, and throwing here
     * would take down the one screen a bouncer key is entitled to.
     */
    #[Test]
    public function nothingConfiguredYieldsNoCredentialsRatherThanAnError(): void
    {
        self::assertNull($this->resolver()->resolve());
    }

    /**
     * Half a credential is a configuration mistake. Logging in with an empty
     * password would be answered 403 and read like a wrong password, sending
     * the operator to rotate a secret that was never set.
     */
    #[Test]
    public function anIdWithoutAPasswordIsNotACredential(): void
    {
        putenv('CROWDSEC_LAPI_MACHINE_ID=env-id');

        self::assertNull($this->resolver()->resolve());
    }

    #[Test]
    public function aTrailingNewlineInASecretFileIsNotPartOfTheSecret(): void
    {
        $credentials = $this->resolver(
            $this->tempFile("padded-id\n"),
            $this->tempFile("padded-password\n"),
        )->resolve();

        self::assertNotNull($credentials);
        self::assertSame('padded-id', $credentials->machineId);
        self::assertSame('padded-password', $credentials->password);
    }

    private function resolver(
        string $idFile = '/nonexistent/crowdsec_machine_id',
        string $passwordFile = '/nonexistent/crowdsec_machine_password',
    ): LapiMachineCredentialsResolver {
        return new LapiMachineCredentialsResolver($idFile, $passwordFile);
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'lapi-machine-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
