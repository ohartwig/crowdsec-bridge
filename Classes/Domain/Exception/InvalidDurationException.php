<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

final class InvalidDurationException extends \InvalidArgumentException
{
    public static function invalidFormat(string $value): self
    {
        return new self(\sprintf(
            'Invalid CrowdSec duration format: "%s". Expected Go-duration like "24h" or "1h30m".',
            $value,
        ));
    }

    public static function nonPositive(string $value): self
    {
        return new self(\sprintf(
            'Duration "%s" parses to zero seconds. A decision with no enforcement window is meaningless — use at least 1s.',
            $value,
        ));
    }
}
