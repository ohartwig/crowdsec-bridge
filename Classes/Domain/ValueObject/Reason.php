<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDecisionDraftException;

final readonly class Reason
{
    public const MIN_LENGTH = 5;
    public const MAX_LENGTH = 1000;

    public string $value;

    public function __construct(string $rawValue)
    {
        $trimmed = trim($rawValue);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_LENGTH) {
            throw InvalidDecisionDraftException::reasonTooShort($length);
        }
        if ($length > self::MAX_LENGTH) {
            throw InvalidDecisionDraftException::reasonTooLong($length);
        }

        $this->value = $trimmed;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
