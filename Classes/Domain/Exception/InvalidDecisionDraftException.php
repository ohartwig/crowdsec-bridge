<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;

final class InvalidDecisionDraftException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $field,
        public readonly string $reasonCode,
    ) {
        parent::__construct($message);
    }

    public static function reasonTooShort(int $actualLength): self
    {
        return new self(
            \sprintf('Reason must be at least 5 characters, got %d', $actualLength),
            'reason',
            'too_short',
        );
    }

    public static function reasonTooLong(int $actualLength): self
    {
        return new self(
            \sprintf('Reason must not exceed 1000 characters, got %d', $actualLength),
            'reason',
            'too_long',
        );
    }

    public static function invalidValueForScope(string $value, DecisionScope $scope): self
    {
        return new self(
            \sprintf('Value "%s" is not valid for scope %s', $value, $scope->value),
            'value',
            'invalid_for_scope',
        );
    }

    public static function emptyValue(): self
    {
        return new self('Value must not be empty', 'value', 'empty');
    }
}
