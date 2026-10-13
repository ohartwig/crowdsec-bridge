<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Audit;

/**
 * Strips control characters (especially CR/LF/TAB) from any string that will be embedded
 * in a single-line sys_log `details` / `message` field. Prevents log forging where an
 * attacker-controlled value (reason, IP, origin, error message) contains newlines and
 * thereby injects a fake follow-up log line for downstream syslog forwarders or SIEM.
 *
 * Security audit H1.
 */
final readonly class LogLineSanitizer
{
    public const MAX_LENGTH = 1000;

    public static function clean(?string $value): string
    {
        if (null === $value) {
            return '';
        }
        // Replace any C0 control character (0x00-0x1F) plus DEL (0x7F) with a single space.
        $sanitised = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value);
        if (mb_strlen($sanitised) > self::MAX_LENGTH) {
            $sanitised = mb_substr($sanitised, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $sanitised;
    }
}
