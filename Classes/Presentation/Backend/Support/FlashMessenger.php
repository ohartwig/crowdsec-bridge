<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\Backend\Support;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend flash message dispatch.
 *
 * Refactor C2: collected the locallang-lookup, placeholder substitution and
 * FlashMessageService.enqueue plumbing into one collaborator so DecisionController
 * stays focused on HTTP routing. Placeholder syntax is {0}, {1}, ... — filled
 * by TranslatorPlaceholders, the same rewrite the templates use.
 */
final readonly class FlashMessenger
{
    public const QUEUE_IDENTIFIER = 'crowdsec_bridge.flash';
    public const EXTENSION_KEY = 'crowdsec_bridge';
    public const DEFAULT_LANG_FILE = 'locallang';

    public function __construct(private FlashMessageService $flashMessageService) {}

    /**
     * @param array<int, int|string> $arguments
     */
    public function enqueue(string $key, ContextualFeedbackSeverity $severity, array $arguments = []): void
    {
        $template = $this->translate($key);
        $rendered = TranslatorPlaceholders::fill($template, $arguments);

        $flash = new FlashMessage($rendered, '', $severity);
        $this->flashMessageService
            ->getMessageQueueByIdentifier(self::QUEUE_IDENTIFIER)
            ->enqueue($flash);
    }

    public function translate(string $key, string $file = self::DEFAULT_LANG_FILE): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if (!$lang instanceof LanguageService) {
            return $key;
        }

        return $lang->sL('LLL:EXT:' . self::EXTENSION_KEY . '/Resources/Private/Language/' . $file . '.xlf:' . $key);
    }
}
