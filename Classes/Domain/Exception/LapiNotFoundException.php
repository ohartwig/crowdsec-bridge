<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

/**
 * Raised when LAPI returns HTTP 404 for a resource we explicitly fetched
 * (single alert by id, decision deletion that has already been removed).
 *
 * Refactor C3: replaces the previous `str_contains($e->getMessage(), 'HTTP 404')`
 * sniffing on LapiUnavailableException. Callers now catch a typed exception
 * with a stable contract instead of pattern-matching a free-form string.
 */
final class LapiNotFoundException extends \RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self('LAPI returned HTTP 404 for ' . $path);
    }
}
