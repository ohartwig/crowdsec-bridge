<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\ValueObject\OperatorPermissions;

/**
 * Resolves the operator permissions of the currently authenticated backend
 * user. Implementations source the user from request/session state — callers
 * never pass it in. That insulates the Presentation layer from the concrete
 * BackendUserAuthentication type (TYPO3 framework) which keeps the deptrac
 * Presentation→Infrastructure rule satisfiable.
 */
interface OperatorPermissionsResolverInterface
{
    public function resolve(): OperatorPermissions;
}
