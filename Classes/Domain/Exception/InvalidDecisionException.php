<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

/**
 * Raised when a Decision VO cannot be constructed because its fields would
 * violate the domain invariants (empty value, missing origin). Refactor C9
 * replaces a generic \InvalidArgumentException so callers can catch this
 * specific failure without also swallowing unrelated argument errors.
 */
final class InvalidDecisionException extends \InvalidArgumentException
{
    public static function emptyValue(): self
    {
        return new self('Decision value must not be empty');
    }

    public static function emptyOrigin(): self
    {
        return new self('Decision origin must not be empty');
    }
}
