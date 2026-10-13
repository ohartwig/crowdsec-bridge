<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Application\Dto;

use Koh\CrowdsecBridge\Application\Dto\AddDecisionResult;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Replaces the add half of WriteResultTest, which used to assert that creating
 * a decision hands back a DecisionId.
 *
 * It does not. `POST /v1/decisions` is answered with 405; a decision is created
 * by posting the alert that carries it, and the response is a list of alert ids.
 * The old assertion was green against a mock that returned whatever its author
 * expected, and it described a number CrowdSec has never sent. What is worth
 * pinning now is that the type says which id this is, so the audit trail and the
 * flash message cannot quietly disagree about what the operator is looking at.
 */
final class AddDecisionResultTest extends TestCase
{
    #[Test]
    public function itCarriesTheAlertIdUnderAnAlertIdType(): void
    {
        $result = new AddDecisionResult(new AlertId(42));

        self::assertSame(42, $result->alertId->toInt());
    }

    /**
     * There is no "not found" branch to report: an add either created the alert
     * or threw, so the result type has no status at all. A caller that had to
     * ask whether a creation succeeded would be asking a question the exception
     * already answered.
     */
    #[Test]
    public function itReportsNoStatusBecauseFailureIsAnException(): void
    {
        self::assertSame(
            ['alertId'],
            array_keys(get_object_vars(new AddDecisionResult(new AlertId(1)))),
        );
    }
}
