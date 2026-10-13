<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Presentation\ViewHelpers;

use Koh\CrowdsecBridge\Presentation\Backend\Support\TranslatorPlaceholders;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * f:translate for this extension's labels, with their {0}-style placeholders.
 *
 * f:translate passes its arguments to vsprintf, so every label of this
 * extension that carries a value rendered with a literal "{0}". FlashMessenger
 * has always rewritten the markers; this is the same rewrite for templates.
 *
 *     <cb:translate key="status.last_check" arguments="{0: checkedAt}"/>
 */
final class TranslateViewHelper extends AbstractViewHelper
{
    private const EXTENSION_NAME = 'CrowdsecBridge';

    public function initializeArguments(): void
    {
        $this->registerArgument('key', 'string', 'Label key in locallang.xlf', true);
        $this->registerArgument('arguments', 'array', 'Values for {0}, {1}, ...', false, []);
    }

    public function render(): string
    {
        $key = (string) $this->arguments['key'];
        $template = LocalizationUtility::translate($key, self::EXTENSION_NAME) ?? $key;
        $arguments = $this->arguments['arguments'];

        return TranslatorPlaceholders::fill($template, \is_array($arguments) ? $arguments : []);
    }
}
