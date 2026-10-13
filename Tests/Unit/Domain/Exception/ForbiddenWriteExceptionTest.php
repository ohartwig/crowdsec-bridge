<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\Exception;

use Koh\CrowdsecBridge\Domain\Exception\ForbiddenWriteException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal to write a decision is written to the audit log, and the
 * reasonCode is what makes that log answerable later: "who was turned away and
 * why". Two refusals sharing a code, or carrying the wrong action, make the
 * trail useless exactly when someone is asking whether an operator was blocked
 * legitimately.
 */
final class ForbiddenWriteExceptionTest extends TestCase
{
    /**
     * @return array<string, array{ForbiddenWriteException, DecisionWriteAction, string}>
     */
    public static function refusals(): array
    {
        return [
            'no operator role' => [
                ForbiddenWriteException::missingOperatorRole(),
                DecisionWriteAction::WriteDenied,
                'no_operator_role',
            ],
            'foreign origin' => [
                ForbiddenWriteException::foreignOriginRequiresAdmin('cscli'),
                DecisionWriteAction::DeleteDenied,
                'requires_admin',
            ],
            'invalid csrf token' => [
                ForbiddenWriteException::csrfTokenInvalid(DecisionWriteAction::Add),
                DecisionWriteAction::CsrfDenied,
                'csrf_token_invalid',
            ],
            'duplicate submit' => [
                ForbiddenWriteException::duplicateSubmit(),
                DecisionWriteAction::DuplicateSubmit,
                'duplicate_submit',
            ],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function eachRefusalCarriesItsOwnActionAndReason(
        ForbiddenWriteException $exception,
        DecisionWriteAction $action,
        string $reasonCode,
    ): void {
        self::assertSame($action, $exception->action);
        self::assertSame($reasonCode, $exception->reasonCode);
        self::assertNotSame('', $exception->getMessage(), 'the message is what a human reads in the log');
    }

    /**
     * The regression worth guarding: the codes are the key the audit log is
     * queried by. Two refusals sharing one would silently merge two different
     * situations into a single line of reporting.
     */
    #[Test]
    public function theReasonCodesAreDistinct(): void
    {
        $codes = array_map(
            static fn(array $case): string => $case[2],
            array_values(self::refusals()),
        );

        self::assertSame($codes, array_unique($codes));
    }

    /**
     * A refused delete is not the same event as a refused write, and the audit
     * log distinguishes them by the action rather than by the message.
     */
    #[Test]
    public function aRefusedDeleteIsNotRecordedAsARefusedWrite(): void
    {
        self::assertNotSame(
            ForbiddenWriteException::missingOperatorRole()->action,
            ForbiddenWriteException::foreignOriginRequiresAdmin('cscli')->action,
        );
    }

    /**
     * The origin appears in the message because it is what the operator has to
     * take to an admin — "which decision was I not allowed to touch".
     */
    #[Test]
    public function theForeignOriginIsNamedInTheMessage(): void
    {
        self::assertStringContainsString(
            'cscli',
            ForbiddenWriteException::foreignOriginRequiresAdmin('cscli')->getMessage(),
        );
    }
}
