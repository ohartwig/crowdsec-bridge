<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Presentation\Backend\Support;

use Koh\CrowdsecBridge\Presentation\Backend\Support\FlashMessenger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Every message the module shows an operator goes through here. Two things are
 * worth pinning.
 *
 * The placeholders in the XLIFF files are written as {0}, {1} — translator
 * convention — and have to become sprintf's %s before the arguments are put in.
 * Getting that wrong shows a literal "{0}" where an IP address belongs.
 *
 * And translation must survive a missing LanguageService. A CLI context or an
 * early request has no $GLOBALS['LANG'], and a message rendered as a raw key is
 * ugly but readable; a fatal there takes the whole request down.
 */
final class FlashMessengerTest extends TestCase
{
    /** @var list<FlashMessage> */
    private array $queued = [];

    private string $queueIdentifier = '';

    private mixed $langBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queued = [];
        $this->queueIdentifier = '';
        $this->langBackup = $GLOBALS['LANG'] ?? null;
        unset($GLOBALS['LANG']);
    }

    protected function tearDown(): void
    {
        if (null === $this->langBackup) {
            unset($GLOBALS['LANG']);
        } else {
            $GLOBALS['LANG'] = $this->langBackup;
        }

        parent::tearDown();
    }

    private function messenger(): FlashMessenger
    {
        $queue = $this->createMock(FlashMessageQueue::class);
        $queue->method('enqueue')->willReturnCallback(
            function (FlashMessage $message): void {
                $this->queued[] = $message;
            },
        );

        $service = $this->createMock(FlashMessageService::class);
        $service->method('getMessageQueueByIdentifier')->willReturnCallback(
            function (string $identifier) use ($queue): FlashMessageQueue {
                $this->queueIdentifier = $identifier;

                return $queue;
            },
        );

        return new FlashMessenger($service);
    }

    /**
     * A LanguageService that hands back a template for the key it is asked for,
     * so the placeholder handling can be observed.
     */
    private function translatingTo(string $template): void
    {
        $lang = $this->createMock(LanguageService::class);
        $lang->method('sL')->willReturn($template);

        $GLOBALS['LANG'] = $lang;
    }

    private function only(): FlashMessage
    {
        self::assertCount(1, $this->queued, 'exactly one message must have been queued');

        return $this->queued[0];
    }

    #[Test]
    public function theMessageGoesIntoTheExtensionsOwnQueue(): void
    {
        $this->messenger()->enqueue('decision.added', ContextualFeedbackSeverity::OK);

        self::assertSame(FlashMessenger::QUEUE_IDENTIFIER, $this->queueIdentifier);
        self::assertSame(ContextualFeedbackSeverity::OK, $this->only()->getSeverity());
    }

    /**
     * Without a LanguageService the key itself is shown. Not pretty, but the
     * alternative — a fatal in a CLI context — takes the request with it.
     */
    #[Test]
    public function aMissingLanguageServiceLeavesTheKeyVisible(): void
    {
        $this->messenger()->enqueue('decision.added', ContextualFeedbackSeverity::OK);

        self::assertSame('decision.added', $this->only()->getMessage());
    }

    #[Test]
    public function anythingThatIsNotALanguageServiceIsIgnoredToo(): void
    {
        $GLOBALS['LANG'] = new \stdClass();

        $this->messenger()->enqueue('decision.added', ContextualFeedbackSeverity::OK);

        self::assertSame('decision.added', $this->only()->getMessage());
    }

    #[Test]
    public function aTranslatedMessageWithoutArgumentsIsPassedThrough(): void
    {
        $this->translatingTo('Decision added.');

        $this->messenger()->enqueue('decision.added', ContextualFeedbackSeverity::OK);

        self::assertSame('Decision added.', $this->only()->getMessage());
    }

    /**
     * The regression worth guarding: XLIFF placeholders are {0}, sprintf wants
     * %s. Skipping the rewrite leaves a literal brace where the address belongs.
     */
    #[Test]
    public function translatorPlaceholdersAreFilledIn(): void
    {
        $this->translatingTo('Banned {0} for {1}.');

        $this->messenger()->enqueue(
            'decision.added',
            ContextualFeedbackSeverity::OK,
            ['203.0.113.42', '4h'],
        );

        self::assertSame('Banned 203.0.113.42 for 4h.', $this->only()->getMessage());
    }

    /**
     * A template that already uses sprintf syntax must not be mangled by the
     * rewrite either.
     */
    #[Test]
    public function aTemplateAlreadyUsingSprintfSyntaxStillWorks(): void
    {
        $this->translatingTo('Banned %s.');

        $this->messenger()->enqueue('decision.added', ContextualFeedbackSeverity::OK, ['203.0.113.42']);

        self::assertSame('Banned 203.0.113.42.', $this->only()->getMessage());
    }

    #[Test]
    public function theSeverityIsCarriedThrough(): void
    {
        foreach ([
            ContextualFeedbackSeverity::ERROR,
            ContextualFeedbackSeverity::WARNING,
            ContextualFeedbackSeverity::INFO,
        ] as $severity) {
            $this->queued = [];
            $this->messenger()->enqueue('some.key', $severity);

            self::assertSame($severity, $this->only()->getSeverity());
        }
    }

    /**
     * translate() is also called directly, for text that is not a flash message.
     */
    #[Test]
    public function translateAnswersTheKeyWhenThereIsNoLanguageService(): void
    {
        self::assertSame('some.key', $this->messenger()->translate('some.key'));
    }
}
