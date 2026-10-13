<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Generates and validates CSRF tokens for a backend form action. Application-
 * layer contract; the concrete implementation lives in Infrastructure (TYPO3
 * FormProtectionFactory). PSR-7 ServerRequestInterface is portable enough to
 * be used in the contract — the Application layer already speaks PSR-7.
 */
interface FormTokenValidatorInterface
{
    public function generate(ServerRequestInterface $request, string $actionName): string;

    public function isValid(ServerRequestInterface $request, string $actionName, string $tokenValue): bool;
}
