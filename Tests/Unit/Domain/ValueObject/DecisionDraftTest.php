<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDecisionDraftException;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionDraft;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use Koh\CrowdsecBridge\Domain\ValueObject\Duration;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use PHPUnit\Framework\TestCase;

final class DecisionDraftTest extends TestCase
{
    public function testIpDraftBuilds(): void
    {
        $draft = new DecisionDraft(
            DecisionScope::Ip,
            DecisionType::Ban,
            '203.0.113.99',
            Duration::fromHours(24),
            new Reason('Smoke-Test'),
        );

        self::assertSame('203.0.113.99', $draft->normalisedValue());
    }

    public function testRangeDraftAcceptsCidr(): void
    {
        $draft = new DecisionDraft(
            DecisionScope::Range,
            DecisionType::Ban,
            '198.51.100.0/24',
            Duration::fromDays(7),
            new Reason('smoke-range'),
        );
        self::assertSame('198.51.100.0/24', $draft->normalisedValue());
    }

    public function testCountryDraftAcceptsIso3166(): void
    {
        $draft = new DecisionDraft(
            DecisionScope::Country,
            DecisionType::Ban,
            'DE',
            Duration::fromDays(30),
            new Reason('country-test'),
        );
        self::assertSame('DE', $draft->normalisedValue());
    }

    public function testCountryRejectsFullName(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new DecisionDraft(
            DecisionScope::Country,
            DecisionType::Ban,
            'DEUTSCHLAND',
            Duration::fromDays(7),
            new Reason('test-rejection'),
        );
    }

    public function testAsnDraftAcceptsValidFormat(): void
    {
        $draft = new DecisionDraft(
            DecisionScope::As,
            DecisionType::Ban,
            'AS15169',
            Duration::fromHours(1),
            new Reason('asn-test'),
        );
        self::assertSame('AS15169', $draft->normalisedValue());
    }

    public function testAsnRejectsMissingPrefix(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new DecisionDraft(
            DecisionScope::As,
            DecisionType::Ban,
            '15169',
            Duration::fromHours(1),
            new Reason('asn-bad'),
        );
    }

    public function testCidrRejectsMissingPrefixLength(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new DecisionDraft(
            DecisionScope::Range,
            DecisionType::Ban,
            '198.51.100.0',
            Duration::fromHours(1),
            new Reason('cidr-bad'),
        );
    }

    public function testEmptyValueRejected(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new DecisionDraft(
            DecisionScope::Ip,
            DecisionType::Ban,
            '',
            Duration::fromHours(1),
            new Reason('empty-test'),
        );
    }

    public function testToLapiPayloadEnforcesTypo3Origin(): void
    {
        $draft = new DecisionDraft(
            DecisionScope::Ip,
            DecisionType::Ban,
            '203.0.113.99',
            Duration::fromHours(24),
            new Reason('Portscanner'),
        );

        $payload = $draft->toLapiPayload(self::createdAt());

        self::assertCount(1, $payload, 'Payload must be single-element alert array');
        $alert = $payload[0];
        self::assertCount(1, $alert['decisions'], 'Each alert must contain exactly one decision');
        self::assertSame('typo3', $alert['decisions'][0]['origin'], 'Origin MUST always be "typo3" — user cannot override');
        self::assertSame('203.0.113.99', $alert['decisions'][0]['value']);
        self::assertSame('Portscanner', $alert['decisions'][0]['scenario']);
        self::assertSame('24h', $alert['decisions'][0]['duration']);
    }

    /**
     * `source.ip` belongs to an address, not to a country code.
     *
     * CrowdSec's Source model has both `value` and `ip`, and repeating "DE" in
     * a field every consumer reads as an address is the kind of thing that
     * looks harmless until something geolocates it.
     */
    public function testTheSourceCarriesAnIpOnlyForTheIpScope(): void
    {
        $ipAlert = new DecisionDraft(
            DecisionScope::Ip,
            DecisionType::Ban,
            '203.0.113.99',
            Duration::fromHours(1),
            new Reason('Portscanner'),
        )->toLapiPayload(self::createdAt())[0];

        self::assertSame('203.0.113.99', $ipAlert['source']['ip']);

        $countryAlert = new DecisionDraft(
            DecisionScope::Country,
            DecisionType::Ban,
            'DE',
            Duration::fromHours(1),
            new Reason('Portscanner'),
        )->toLapiPayload(self::createdAt())[0];

        self::assertArrayNotHasKey('ip', $countryAlert['source']);
        self::assertSame('DE', $countryAlert['source']['value']);
    }

    /**
     * The autonomous-system scope goes out the way CrowdSec stores it, so the
     * decision the module creates is the decision the module can read back.
     */
    public function testTheAutonomousSystemScopeIsSentUppercased(): void
    {
        $alert = new DecisionDraft(
            DecisionScope::As,
            DecisionType::Ban,
            'AS64496',
            Duration::fromHours(1),
            new Reason('Portscanner'),
        )->toLapiPayload(self::createdAt())[0];

        self::assertSame('AS', $alert['source']['scope']);
        self::assertSame('AS', $alert['decisions'][0]['scope']);
    }

    private static function createdAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-01T10:30:00+02:00');
    }
}
