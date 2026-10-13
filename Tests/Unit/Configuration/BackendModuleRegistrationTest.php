<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TYPO3 14 resolves `access` through a registry of access gates
 * (ModuleProvider::accessGranted). An unknown value is not an error and not a
 * deprecation — the lookup misses and the method returns false, so the module
 * is denied to every user, admins included, and simply never appears in the
 * module menu. Nothing is logged.
 *
 * That is how `user,group` — the valid spelling up to v12 — silently removed
 * this module from the backend. The value has to be checked against the gates
 * that actually exist, because being a plausible string is not enough.
 */
final class BackendModuleRegistrationTest extends TestCase
{
    /**
     * Gates shipped by typo3/cms-backend, each declared via
     * #[AsModuleAccessGate(identifier: ...)]. An empty value is legal too: it
     * means "no access restriction" and returns early in accessGranted().
     */
    private const REGISTERED_GATES = ['', 'user', 'admin', 'systemMaintainer'];

    /**
     * @return array<string, array<string, mixed>>
     */
    private function modules(): array
    {
        return require dirname(__DIR__, 3) . '/Configuration/Backend/Modules.php';
    }

    #[Test]
    public function everyModuleUsesARegisteredAccessGate(): void
    {
        foreach ($this->modules() as $identifier => $module) {
            $access = $module['access'] ?? '';

            self::assertContains(
                $access,
                self::REGISTERED_GATES,
                sprintf(
                    'Module "%s" declares access "%s", which no TYPO3 access gate is registered for. '
                    . 'The module would be hidden from every backend user without any error.',
                    $identifier,
                    $access
                )
            );
        }
    }

    #[Test]
    public function theDecisionModuleStaysReachableForNonAdminOperators(): void
    {
        $modules = $this->modules();

        self::assertArrayHasKey('system_crowdsec', $modules);
        // 'user' delegates to $user->check('modules', ...): admins pass
        // unconditionally, everyone else needs the module permission on their
        // group. 'admin' would lock operators out of their own module.
        self::assertSame('user', $modules['system_crowdsec']['access']);
    }
}
