<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\Alert;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;
use Koh\CrowdsecBridge\Domain\ValueObject\AlertSource;
use Koh\CrowdsecBridge\Domain\ValueObject\DecisionScope;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    public function testConstructsWithRequiredFields(): void
    {
        $alert = new Alert(
            id: new AlertId(5),
            scenario: 'crowdsecurity/ssh-bf',
            createdAt: new \DateTimeImmutable('2026-05-20T10:00:00+00:00'),
            source: new AlertSource(scopeRaw: 'Ip', value: '1.2.3.4', scope: DecisionScope::Ip),
            decisionCount: 3,
        );

        self::assertSame(5, $alert->id->toInt());
        self::assertSame([], $alert->decisions, 'list mode → decisions array empty by default');
        self::assertSame([], $alert->labels);
        self::assertSame(3, $alert->decisionCount);
    }

    public function testAlertIdRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AlertId(0);
    }
}
