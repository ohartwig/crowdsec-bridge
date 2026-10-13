<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Infrastructure\Http;

use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Infrastructure\Http\LapiResponseParser;
use PHPUnit\Framework\TestCase;

final class LapiResponseParserAlertsTest extends TestCase
{
    private LapiResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LapiResponseParser();
    }

    public function testEmptyAlertsList(): void
    {
        self::assertSame([], $this->parser->parseAlerts(null, false));
        self::assertSame([], $this->parser->parseAlerts([], false));
    }

    public function testListModeDropsEmbeddedDecisions(): void
    {
        $payload = [[
            'id' => 7,
            'scenario' => 'crowdsecurity/ssh-bf',
            'created_at' => '2026-05-20T10:00:00Z',
            'source' => ['scope' => 'Ip', 'value' => '1.2.3.4'],
            'decisions' => [
                ['id' => 1, 'scope' => 'Ip', 'type' => 'ban', 'value' => '1.2.3.4', 'duration' => '1h', 'origin' => 'crowdsec', 'scenario' => 'x'],
                ['id' => 2, 'scope' => 'Ip', 'type' => 'ban', 'value' => '1.2.3.4', 'duration' => '1h', 'origin' => 'crowdsec', 'scenario' => 'x'],
                ['id' => 3, 'scope' => 'Ip', 'type' => 'ban', 'value' => '1.2.3.4', 'duration' => '1h', 'origin' => 'crowdsec', 'scenario' => 'x'],
            ],
        ]];

        $alerts = $this->parser->parseAlerts($payload, false);

        self::assertCount(1, $alerts);
        self::assertSame(3, $alerts[0]->decisionCount);
        self::assertSame([], $alerts[0]->decisions, 'list mode must drop the embedded decisions array');
    }

    public function testDetailModeKeepsEmbeddedDecisions(): void
    {
        $payload = [
            'id' => 7,
            'scenario' => 'crowdsecurity/ssh-bf',
            'created_at' => '2026-05-20T10:00:00Z',
            'source' => ['scope' => 'Ip', 'value' => '1.2.3.4'],
            'decisions' => [
                ['id' => 1, 'scope' => 'Ip', 'type' => 'ban', 'value' => '1.2.3.4', 'duration' => '1h', 'origin' => 'crowdsec', 'scenario' => 'x'],
                ['id' => 2, 'scope' => 'Ip', 'type' => 'ban', 'value' => '1.2.3.4', 'duration' => '1h', 'origin' => 'crowdsec', 'scenario' => 'x'],
            ],
        ];

        $alert = $this->parser->parseAlert($payload, true);

        self::assertNotNull($alert);
        self::assertCount(2, $alert->decisions, 'detail mode must keep the decisions');
        self::assertSame(2, $alert->decisionCount);
    }

    public function testUnknownSourceScopeTolerated(): void
    {
        $payload = [[
            'id' => 1,
            'scenario' => 'x',
            'created_at' => '2026-05-20T10:00:00Z',
            'source' => ['scope' => 'Galaxy', 'value' => 'stardust'],
        ]];

        $alerts = $this->parser->parseAlerts($payload, false);

        self::assertSame('Galaxy', $alerts[0]->source->scopeRaw);
        self::assertNull($alerts[0]->source->scope);
    }

    public function testMissingScenarioParsesAsEmptyString(): void
    {
        // Refactor C5: the parser no longer hardcodes a German label.
        // Localization of the "unknown" fallback is the template's job.
        $payload = [[
            'id' => 1,
            'created_at' => '2026-05-20T10:00:00Z',
            'source' => ['scope' => 'Ip', 'value' => '1.2.3.4'],
        ]];

        $alerts = $this->parser->parseAlerts($payload, false);
        self::assertSame('', $alerts[0]->scenario);
    }

    public function testMissingCreatedAtIsInvalid(): void
    {
        $this->expectException(LapiUnavailableException::class);
        $this->parser->parseAlerts([[
            'id' => 1,
            'scenario' => 'x',
            'source' => ['scope' => 'Ip', 'value' => '1.2.3.4'],
        ]], false);
    }

    public function testMalformedAlertEntry(): void
    {
        $this->expectException(LapiUnavailableException::class);
        $this->parser->parseAlerts(['not-an-object'], false);
    }

    public function testParseAlertReturnsNullForEmptyPayload(): void
    {
        self::assertNull($this->parser->parseAlert(null, true));
        self::assertNull($this->parser->parseAlert([], true));
    }

    public function testAlertSourceWithGeoFields(): void
    {
        $payload = [
            'id' => 1,
            'scenario' => 'x',
            'created_at' => '2026-05-20T10:00:00Z',
            'source' => [
                'scope' => 'Ip',
                'value' => '1.2.3.4',
                'ip' => '1.2.3.4',
                'as_number' => 15169,
                'as_name' => 'GOOGLE',
                'cn' => 'US',
                'latitude' => 37.4,
                'longitude' => -122.0,
            ],
        ];

        $alert = $this->parser->parseAlert($payload, false);

        self::assertNotNull($alert);
        self::assertSame(DecisionScope::Ip, $alert->source->scope);
        self::assertSame(15169, $alert->source->asNumber);
        self::assertSame('GOOGLE', $alert->source->asName);
        self::assertSame('US', $alert->source->country);
        self::assertTrue($alert->source->hasGeo());
    }
}
