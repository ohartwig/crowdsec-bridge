<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionType;
use PHPUnit\Framework\TestCase;
use ValueError;

final class DecisionScopeTest extends TestCase
{
    public function testFromLapiAcceptsAllScopes(): void
    {
        self::assertSame(DecisionScope::Ip, DecisionScope::fromLapi('Ip'));
        self::assertSame(DecisionScope::Range, DecisionScope::fromLapi('Range'));
        self::assertSame(DecisionScope::Country, DecisionScope::fromLapi('Country'));
        self::assertSame(DecisionScope::As, DecisionScope::fromLapi('As'));
    }

    /**
     * The one casing CrowdSec really sends for the autonomous-system scope.
     *
     * types.NormalizeScope() uppercases it, so `AS` is what comes back for a
     * decision created as `As`. Before this, the parser caught the resulting
     * ValueError, skipped the entry and logged a warning — the decision blocked
     * traffic and was invisible in the module, with nothing failing anywhere.
     */
    public function testFromLapiAcceptsTheUppercasedAutonomousSystemScope(): void
    {
        self::assertSame(DecisionScope::As, DecisionScope::fromLapi('AS'));
        self::assertSame(DecisionScope::As, DecisionScope::tryFromLapi('AS'));
    }

    public function testTheAutonomousSystemScopeIsWrittenBackAsCrowdsecSpellsIt(): void
    {
        self::assertSame('AS', DecisionScope::As->toLapi());
        self::assertSame('Ip', DecisionScope::Ip->toLapi());
        self::assertSame('Range', DecisionScope::Range->toLapi());
        self::assertSame('Country', DecisionScope::Country->toLapi());
    }

    /**
     * `AS` is the documented exception, not the start of case-insensitive
     * matching: a lowercase scope is still a scope this module does not know.
     */
    public function testFromLapiIsOtherwiseCaseSensitive(): void
    {
        self::assertNull(DecisionScope::tryFromLapi('as'));

        $this->expectException(\ValueError::class);
        DecisionScope::fromLapi('ip');
    }

    public function testFromLapiRejectsUnknown(): void
    {
        $this->expectException(\ValueError::class);
        DecisionScope::fromLapi('Galaxy');
    }

    public function testDecisionTypeAcceptsLowercaseValues(): void
    {
        self::assertSame(DecisionType::Ban, DecisionType::fromLapi('ban'));
        self::assertSame(DecisionType::Captcha, DecisionType::fromLapi('captcha'));
    }

    public function testDecisionTypeRejectsUppercase(): void
    {
        $this->expectException(\ValueError::class);
        DecisionType::fromLapi('Ban');
    }
}
