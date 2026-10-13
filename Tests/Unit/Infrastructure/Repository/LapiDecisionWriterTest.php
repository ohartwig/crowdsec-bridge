<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Repository;

use Koh\CrowdsecBridge\Domain\Contract\ClockInterface;
use Koh\CrowdsecBridge\Domain\Contract\LapiClientInterface;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionId;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiDecisionWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Writing a decision means banning an address, and it is done by posting the
 * alert that carries it: `POST /v1/decisions` does not exist and is answered
 * with 405 before any credential is looked at.
 *
 * What comes back is a list of **alert** ids. This class used to assert that it
 * was a list of decision ids, and the mock obliged — so the id recorded in the
 * audit trail addressed a row that had nothing to do with the ban. LAPI returns
 * the id as a bare array element and the type varies by version, string in some
 * and int in others; the rest of these tests are about not turning a malformed
 * answer into a plausible-looking id.
 */
final class LapiDecisionWriterTest extends TestCase
{
    private function draft(): DecisionDraft
    {
        return new DecisionDraft(
            DecisionScope::Ip,
            DecisionType::Ban,
            '203.0.113.10',
            new Duration('4h'),
            new Reason('manual block'),
        );
    }

    /**
     * @param list<mixed> $response
     */
    private function writerAnswering(array $response): LapiDecisionWriter
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('post')->willReturn($response);

        return new LapiDecisionWriter($client, self::clock());
    }

    /**
     * A fixed clock, because start_at/stop_at are part of the payload and an
     * assertion against "now" would be an assertion against the test's own
     * runtime.
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-08-01T10:30:00+02:00');
            }
        };
    }

    /**
     * Replaces theDraftIsPostedToTheDecisionsEndpoint(), which pinned an address
     * CrowdSec answers with 405.
     */
    #[Test]
    public function theDraftIsPostedAsAnAlertAndTheAlertIdComesBack(): void
    {
        $captured = null;

        $client = $this->createMock(LapiClientInterface::class);
        $client->method('post')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = [$path, $body];

                return [42];
            },
        );

        $id = new LapiDecisionWriter($client, self::clock())->add($this->draft());

        self::assertSame(42, $id->toInt());
        self::assertNotNull($captured, 'the client must have been called');
        self::assertSame('/v1/alerts', $captured[0]);
        self::assertSame($this->draft()->toLapiPayload(self::clock()->now()), $captured[1]);
    }

    /**
     * The nine fields beyond "a decision" that CrowdSec's Alert model marks
     * Required. Missing one of them is not a partial success — go-swagger
     * rejects the whole batch, and the operator is told the ban failed.
     */
    #[Test]
    public function everyRequiredAlertFieldIsOnTheWire(): void
    {
        $captured = null;

        $client = $this->createMock(LapiClientInterface::class);
        $client->method('post')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = $body;

                return [42];
            },
        );

        new LapiDecisionWriter($client, self::clock())->add($this->draft());

        self::assertIsArray($captured);
        $alert = $captured[0];

        foreach ([
            'capacity', 'decisions', 'events', 'events_count', 'leakspeed', 'message',
            'scenario', 'scenario_hash', 'scenario_version', 'simulated', 'source',
            'start_at', 'stop_at',
        ] as $field) {
            self::assertArrayHasKey($field, $alert, $field . ' is Required in CrowdSec\'s Alert model');
        }

        self::assertSame('2026-08-01T08:30:00Z', $alert['start_at'], 'timestamps go out as UTC');
        self::assertSame('2026-08-01T08:30:00Z', $alert['stop_at']);
    }

    /**
     * Older LAPI versions answer with the id as a string. Rejecting that would
     * fail a write that actually succeeded — the ban would be in place while the
     * backend reports an error.
     */
    #[Test]
    public function anIdArrivingAsAStringIsAccepted(): void
    {
        self::assertSame(42, $this->writerAnswering(['42'])->add($this->draft())->toInt());
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function malformedResponses(): array
    {
        return [
            'empty response' => [[]],
            'null instead of an id' => [[null]],
            'an object instead of an id' => [[['id' => 42]]],
            'a boolean' => [[true]],
            'zero' => [[0]],
            'a negative id' => [[-5]],
            'a non-numeric string' => [['not-an-id']],
        ];
    }

    /**
     * The regression that matters: none of these may become a DecisionId. A
     * caller that receives one would record a ban under an id that does not
     * exist, and the ban could never be lifted through the module.
     *
     * @param list<mixed> $response
     */
    #[Test]
    #[DataProvider('malformedResponses')]
    public function aMalformedResponseIsRefusedRatherThanTurnedIntoAnId(array $response): void
    {
        $writer = $this->writerAnswering($response);

        $this->expectException(LapiUnavailableException::class);

        $writer->add($this->draft());
    }

    /**
     * Additional ids beyond the first are ignored — a single draft produces a
     * single decision, and the first element is the one LAPI documents.
     */
    #[Test]
    public function onlyTheFirstIdIsUsed(): void
    {
        self::assertSame(7, $this->writerAnswering([7, 8, 9])->add($this->draft())->toInt());
    }

    #[Test]
    public function deletingAddressesTheDecisionById(): void
    {
        $captured = null;

        $client = $this->createMock(LapiClientInterface::class);
        $client->method('delete')->willReturnCallback(
            static function (string $path) use (&$captured): bool {
                $captured = $path;

                return true;
            },
        );

        self::assertTrue(new LapiDecisionWriter($client, self::clock())->delete(new DecisionId(42)));
        self::assertSame('/v1/decisions/42', $captured);
    }

    /**
     * A delete LAPI did not carry out must not read as success, or the operator
     * is told a ban was lifted while it is still in force.
     */
    #[Test]
    public function aRefusedDeleteIsReportedAsFailure(): void
    {
        $client = $this->createMock(LapiClientInterface::class);
        $client->method('delete')->willReturn(false);

        self::assertFalse(new LapiDecisionWriter($client, self::clock())->delete(new DecisionId(42)));
    }
}
