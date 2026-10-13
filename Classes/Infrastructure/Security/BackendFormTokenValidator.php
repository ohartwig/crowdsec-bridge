<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Security;

use Koh\CrowdsecBridge\Application\Contract\FormTokenValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\FormProtection\AbstractFormProtection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;

/**
 * Wraps TYPO3's FormProtectionFactory so the controller can validate / generate CSRF tokens
 * without depending on the deprecated static createForType() entry point.
 *
 * TYPO3 14 prefers createFromRequest($request) (introduced in 12.1) whenever a PSR-7 request
 * is in scope — which is always the case for backend controller actions.
 *
 * Token name is constant "crowdsec_bridge"; action name differentiates add/delete/etc.
 */
final readonly class BackendFormTokenValidator implements FormTokenValidatorInterface
{
    public const TOKEN_NAME = 'crowdsec_bridge';

    public function __construct(private FormProtectionFactory $factory) {}

    public function generate(ServerRequestInterface $request, string $actionName): string
    {
        return $this->protection($request)->generateToken(self::TOKEN_NAME, $actionName);
    }

    public function isValid(ServerRequestInterface $request, string $actionName, string $tokenValue): bool
    {
        if ('' === $tokenValue) {
            return false;
        }

        return $this->protection($request)->validateToken($tokenValue, self::TOKEN_NAME, $actionName);
    }

    private function protection(ServerRequestInterface $request): AbstractFormProtection
    {
        return $this->factory->createFromRequest($request);
    }
}
