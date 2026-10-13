<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Permissions;

use Koh\CrowdsecBridge\Domain\Contract\OperatorPermissionsResolverInterface;
use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Builds OperatorPermissions for the current backend request from TYPO3 ACL state
 * and the extension's operatorGroupIds / allowDeleteForeignDecisions toggles.
 *
 * Clarify Q2 enforces: canDeleteForeign requires BOTH isAdmin AND config flag.
 *
 * Security audit H4: group membership is matched against the configured UID
 * list, not against the (mutable, forgeable) group title.
 */
final readonly class OperatorPermissionsFactory implements OperatorPermissionsResolverInterface
{
    public const MODULE_IDENTIFIER = 'system_crowdsec';
    public const EXTENSION_KEY = 'crowdsec_bridge';
    public const FOREIGN_OVERRIDE_KEY = 'allowDeleteForeignDecisions';
    public const OPERATOR_GROUP_IDS_KEY = 'operatorGroupIds';

    public function __construct(private ExtensionConfiguration $extensionConfiguration) {}

    /**
     * Reads the currently authenticated BE user from $GLOBALS['BE_USER'] and
     * derives the permissions VO. The framework lookup lives here so callers
     * (Presentation) only see the Domain contract.
     */
    public function resolve(): OperatorPermissions
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->build($user instanceof BackendUserAuthentication ? $user : null);
    }

    public function build(?BackendUserAuthentication $user): OperatorPermissions
    {
        if (null === $user) {
            return new OperatorPermissions(false, false, false, false);
        }

        $canRead = (bool) $user->check('modules', self::MODULE_IDENTIFIER);
        $isAdmin = (bool) $user->isAdmin();
        $isOperator = $isAdmin || $this->isInOperatorGroup($user);
        $canWrite = $canRead && $isOperator;

        $foreignConfigActive = $this->isForeignOverrideActive();
        $canDeleteForeign = $canWrite && $isAdmin && $foreignConfigActive;

        return new OperatorPermissions(
            canRead: $canRead,
            canWrite: $canWrite,
            canDeleteOwn: $canWrite,
            canDeleteForeign: $canDeleteForeign,
        );
    }

    private function isInOperatorGroup(BackendUserAuthentication $user): bool
    {
        $configuredIds = $this->configuredOperatorGroupIds();
        if ([] === $configuredIds) {
            return false;
        }
        foreach ($user->userGroupsUID as $groupId) {
            if (!is_numeric($groupId)) {
                continue;
            }
            if (\in_array((int) $groupId, $configuredIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function configuredOperatorGroupIds(): array
    {
        try {
            $raw = $this->extensionConfiguration->get(self::EXTENSION_KEY, self::OPERATOR_GROUP_IDS_KEY);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = trim($part);
            if ('' !== $trimmed && ctype_digit($trimmed)) {
                $ids[] = (int) $trimmed;
            }
        }

        return $ids;
    }

    private function isForeignOverrideActive(): bool
    {
        try {
            $value = $this->extensionConfiguration->get(self::EXTENSION_KEY, self::FOREIGN_OVERRIDE_KEY);
        } catch (\Throwable) {
            return false;
        }

        return '1' === $value || 1 === $value || true === $value;
    }
}
