<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Permissions;

use Koh\CrowdsecBridge\Infrastructure\Permissions\OperatorPermissionsFactory;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class OperatorPermissionsFactoryTest extends TestCase
{
    private const OPERATOR_GROUP_ID = 42;

    public function testNullUserHasNoPermissions(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', (string) self::OPERATOR_GROUP_ID));
        $perms = $factory->build(null);

        self::assertFalse($perms->canRead);
        self::assertFalse($perms->canWrite);
        self::assertFalse($perms->canDeleteOwn);
        self::assertFalse($perms->canDeleteForeign);
    }

    public function testOperatorWithoutAdminAndConfigOff(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', (string) self::OPERATOR_GROUP_ID));
        $user = $this->user(check: true, isAdmin: false, groupIds: [self::OPERATOR_GROUP_ID]);

        $perms = $factory->build($user);

        self::assertTrue($perms->canWrite);
        self::assertTrue($perms->canDeleteOwn);
        self::assertFalse($perms->canDeleteForeign);
    }

    public function testAdminWithConfigOffStillCannotDeleteForeign(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', ''));
        $user = $this->user(check: true, isAdmin: true, groupIds: []);

        $perms = $factory->build($user);

        self::assertTrue($perms->canWrite);
        self::assertFalse($perms->canDeleteForeign, 'Config flag must be active for foreign delete');
    }

    public function testAdminWithConfigOnCanDeleteForeign(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('1', ''));
        $user = $this->user(check: true, isAdmin: true, groupIds: []);

        $perms = $factory->build($user);

        self::assertTrue($perms->canDeleteForeign);
    }

    public function testOperatorWithConfigOnButNotAdminCannotDeleteForeign(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('1', (string) self::OPERATOR_GROUP_ID));
        $user = $this->user(check: true, isAdmin: false, groupIds: [self::OPERATOR_GROUP_ID]);

        $perms = $factory->build($user);

        self::assertTrue($perms->canWrite);
        self::assertFalse($perms->canDeleteForeign, 'Operator without admin status must be denied');
    }

    public function testViewerOnlyHasReadButNoWrite(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', (string) self::OPERATOR_GROUP_ID));
        $user = $this->user(check: true, isAdmin: false, groupIds: [99]);

        $perms = $factory->build($user);

        self::assertTrue($perms->canRead);
        self::assertFalse($perms->canWrite);
        self::assertFalse($perms->canDeleteOwn);
    }

    public function testEmptyOperatorIdListDeniesNonAdminWrite(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', ''));
        $user = $this->user(check: true, isAdmin: false, groupIds: [42, 99]);

        $perms = $factory->build($user);

        self::assertTrue($perms->canRead);
        self::assertFalse($perms->canWrite, 'Without operatorGroupIds no non-admin may write');
    }

    public function testOperatorIdListAcceptsAnyMatchingGroup(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', '10, 20 , 30'));
        $user = $this->user(check: true, isAdmin: false, groupIds: [5, 20]);

        $perms = $factory->build($user);

        self::assertTrue($perms->canWrite, 'Group 20 is in the configured operator list');
    }

    public function testOperatorIdListIgnoresNonNumericEntries(): void
    {
        $factory = new OperatorPermissionsFactory($this->extensionConfig('0', 'crowdsec_operator, ,;7'));
        $user = $this->user(check: true, isAdmin: false, groupIds: [7]);

        $perms = $factory->build($user);

        // Non-numeric tokens (incl. the literal "crowdsec_operator" title) are dropped.
        // ";7" contains a separator and is therefore not pure digits, so it is dropped too.
        self::assertFalse($perms->canWrite);
    }

    private function extensionConfig(string $foreignFlag, string $operatorIds): ExtensionConfiguration
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('get')->willReturnCallback(
            static function (string $extKey, string $field) use ($foreignFlag, $operatorIds): string {
                return match ($field) {
                    'allowDeleteForeignDecisions' => $foreignFlag,
                    'operatorGroupIds' => $operatorIds,
                    default => '',
                };
            }
        );

        return $config;
    }

    /**
     * @param list<int> $groupIds
     */
    private function user(bool $check, bool $isAdmin, array $groupIds): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('check')->willReturn($check);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->userGroupsUID = $groupIds;

        return $user;
    }
}
