<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

final class LapiUnavailableException extends \RuntimeException
{
    public static function timeout(string $reason = ''): self
    {
        return new self('LAPI request timed out' . ('' !== $reason ? ': ' . $reason : ''));
    }

    public static function serverError(int $statusCode): self
    {
        return new self(\sprintf('LAPI returned server error %d', $statusCode));
    }

    public static function invalidResponse(string $reason): self
    {
        return new self('LAPI returned invalid response: ' . $reason);
    }
}
