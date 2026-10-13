<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\Backend\Support;

/**
 * Fills the {0}, {1}, ... placeholders the XLIFF files are written with.
 *
 * TYPO3's translation API hands its arguments to vsprintf, which knows %s and
 * nothing else - so a label like "Last check: {0}" put through f:translate
 * keeps its literal "{0}" (seen in the backend on 2026-10-13). The markers are
 * positional, not sequential: "remaining {1}h {2}m" takes the second and third
 * value of [days, hours, minutes], so each {N} becomes %(N+1)$s rather than a
 * bare %s.
 */
final class TranslatorPlaceholders
{
    /**
     * @param array<int, mixed> $arguments
     */
    public static function fill(string $template, array $arguments): string
    {
        if ([] === $arguments) {
            return $template;
        }

        $sprintfTemplate = preg_replace_callback(
            '/\{(\d+)\}/',
            static fn(array $match): string => '%' . ((int) $match[1] + 1) . '$s',
            $template,
        ) ?? $template;

        try {
            return vsprintf($sprintfTemplate, array_map(
                static fn(mixed $value): string => \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '',
                array_values($arguments),
            ));
        } catch (\ValueError) {
            // A label asking for more values than it was given: show it
            // unfilled rather than take the whole module screen down.
            return $template;
        }
    }
}
