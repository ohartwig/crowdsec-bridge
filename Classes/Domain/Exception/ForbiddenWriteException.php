<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;

final class ForbiddenWriteException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly DecisionWriteAction $action,
        public readonly string $reasonCode,
    ) {
        parent::__construct($message);
    }

    public static function missingOperatorRole(): self
    {
        return new self(
            'Backend user does not have crowdsec_operator role required for write operations',
            DecisionWriteAction::WriteDenied,
            'no_operator_role',
        );
    }

    public static function foreignOriginRequiresAdmin(string $origin): self
    {
        return new self(
            \sprintf('Decision with origin "%s" can only be deleted by TYPO3 admins with allowDeleteForeignDecisions enabled', $origin),
            DecisionWriteAction::DeleteDenied,
            'requires_admin',
        );
    }

    public static function csrfTokenInvalid(DecisionWriteAction $attempted): self
    {
        return new self(
            'Backend form token (CSRF) missing or invalid',
            DecisionWriteAction::CsrfDenied,
            'csrf_token_invalid',
        );
    }

    public static function duplicateSubmit(): self
    {
        return new self(
            'Submit token already consumed — duplicate submission detected',
            DecisionWriteAction::DuplicateSubmit,
            'duplicate_submit',
        );
    }
}
