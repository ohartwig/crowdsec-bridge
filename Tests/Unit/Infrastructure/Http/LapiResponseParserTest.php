<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use PHPUnit\Framework\TestCase;

final class LapiResponseParserTest extends TestCase
{
    private LapiResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LapiResponseParser();
    }

    public function testParsesEmptyDecisionList(): void
    {
        self::assertSame([], $this->parser->parseDecisions(null));
        self::assertSame([], $this->parser->parseDecisions([]));
    }

    public function testParsesSingleDecision(): void
    {
        $payload = [[
            'id' => 42,
            'scope' => 'Ip',
            'type' => 'ban',
            'value' => '203.0.113.42',
            'duration' => '24h',
            'origin' => 'cscli',
            'scenario' => 'manual test',
            'until' => '2026-05-22T12:00:00Z',
        ]];

        $decisions = $this->parser->parseDecisions($payload);

        self::assertCount(1, $decisions);
        $decision = $decisions[0];
        self::assertSame(42, $decision->id->toInt());
        self::assertSame(DecisionScope::Ip, $decision->scope);
        self::assertSame(DecisionType::Ban, $decision->type);
        self::assertSame('203.0.113.42', $decision->value);
        self::assertSame('24h', $decision->originalDuration->value);
        self::assertSame('cscli', $decision->origin);
        self::assertSame('manual test', $decision->scenario);
        self::assertNotNull($decision->expiresAt);
    }

    public function testParsesDecisionWithoutUntil(): void
    {
        $payload = [[
            'id' => 1,
            'scope' => 'Range',
            'type' => 'ban',
            'value' => '198.51.100.0/24',
            'duration' => '7d',
            'origin' => 'crowdsec',
            'scenario' => 'crowdsec/ssh-bf',
        ]];

        $decisions = $this->parser->parseDecisions($payload);

        self::assertCount(1, $decisions);
        self::assertNull($decisions[0]->expiresAt);
    }

    public function testRejectsMalformedEntry(): void
    {
        $this->expectException(LapiUnavailableException::class);
        $this->parser->parseDecisions(['not-an-object']);
    }

    public function testSkipsUnknownScopeButKeepsBatch(): void
    {
        $payload = [
            [
                'id' => 1,
                'scope' => 'Galaxy',
                'type' => 'ban',
                'value' => 'x',
                'duration' => '1h',
                'origin' => 'cscli',
                'scenario' => 'x',
            ],
            [
                'id' => 2,
                'scope' => 'Ip',
                'type' => 'ban',
                'value' => '1.1.1.1',
                'duration' => '1h',
                'origin' => 'cscli',
                'scenario' => 'ok',
            ],
        ];

        $result = $this->parser->parseDecisions($payload);

        self::assertCount(1, $result, 'unknown-scope entry skipped, valid entry preserved');
        self::assertSame(2, $result[0]->id->toInt());
    }

    public function testSkipsInvalidDurationButKeepsBatch(): void
    {
        $payload = [
            [
                'id' => 1,
                'scope' => 'Ip',
                'type' => 'ban',
                'value' => '1.1.1.1',
                'duration' => 'forever',
                'origin' => 'cscli',
                'scenario' => 'x',
            ],
        ];

        self::assertSame([], $this->parser->parseDecisions($payload));
    }

    public function testHeartbeatPrefersHeaderVersion(): void
    {
        $status = $this->parser->parseHeartbeat(
            ['version' => 'v1.5.0'],
            ['X-Crowdsec-Version' => ['v1.6.4']],
            new \DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            'typo3-bridge',
        );

        self::assertTrue($status->isReachable);
        self::assertSame('v1.6.4', $status->version);
        self::assertSame('typo3-bridge', $status->bouncerName);
    }

    public function testHeartbeatFallsBackToBodyVersion(): void
    {
        $status = $this->parser->parseHeartbeat(
            ['version' => 'v1.5.0'],
            [],
            new \DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            null,
        );

        self::assertSame('v1.5.0', $status->version);
    }

    public function testHeartbeatUnknownVersionWhenNothingProvided(): void
    {
        $status = $this->parser->parseHeartbeat(
            null,
            [],
            new \DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            null,
        );

        self::assertSame('unknown', $status->version);
    }
}
