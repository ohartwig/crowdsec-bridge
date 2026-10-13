<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Dto;

use Koh\CrowdsecBridge\Application\Dto\WriteResult;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The outcome an operator is shown after lifting a ban. The one distinction
 * that carries meaning is delete-versus-already-gone: both are acceptable
 * outcomes, but only one of them means this request did something. Collapsing
 * them would make a no-op look like a successful unban.
 *
 * Creating a decision no longer produces a WriteResult — LAPI answers a
 * creation with an alert id and has no "already there" outcome to report, so
 * that path has its own type. See AddDecisionResultTest.
 */
final class WriteResultTest extends TestCase
{
    #[Test]
    public function aDeleteThatRemovedSomethingIsASuccess(): void
    {
        $result = WriteResult::ofDelete(true, new DecisionId(42));

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isNotFound());
        self::assertSame(42, $result->decisionId->toInt());
    }

    /**
     * The regression worth guarding: LAPI answers a delete for a decision that
     * is no longer there with "nothing removed". That is not an error, but it is
     * not a success either — reporting it as one tells the operator they lifted
     * a ban when they lifted nothing.
     */
    #[Test]
    public function aDeleteThatRemovedNothingIsReportedAsNotFound(): void
    {
        $result = WriteResult::ofDelete(false, new DecisionId(42));

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isNotFound());
        self::assertSame(42, $result->decisionId->toInt(), 'the id stays available for the message');
    }

    /**
     * The two predicates are the whole interface to the outcome, so they must
     * never both hold or both fail.
     */
    #[Test]
    public function theOutcomeIsAlwaysExactlyOneOfTheTwo(): void
    {
        foreach ([
            WriteResult::ofDelete(true, new DecisionId(2)),
            WriteResult::ofDelete(false, new DecisionId(3)),
        ] as $result) {
            self::assertNotSame(
                $result->isSuccess(),
                $result->isNotFound(),
                'status ' . $result->status,
            );
        }
    }
}
