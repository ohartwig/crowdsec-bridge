<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\ValueObject\DecisionWriteAction;
use PHPUnit\Framework\TestCase;

final class DecisionWriteActionTest extends TestCase
{
    public function testSysLogTypeMapping(): void
    {
        self::assertSame('crowdsec_bridge:decision_add', DecisionWriteAction::Add->toSysLogType());
        self::assertSame('crowdsec_bridge:decision_delete', DecisionWriteAction::Delete->toSysLogType());
        self::assertSame('crowdsec_bridge:write_denied', DecisionWriteAction::WriteDenied->toSysLogType());
        self::assertSame('crowdsec_bridge:delete_denied', DecisionWriteAction::DeleteDenied->toSysLogType());
        self::assertSame('crowdsec_bridge:csrf_denied', DecisionWriteAction::CsrfDenied->toSysLogType());
        self::assertSame('crowdsec_bridge:duplicate_submit', DecisionWriteAction::DuplicateSubmit->toSysLogType());
    }
}
