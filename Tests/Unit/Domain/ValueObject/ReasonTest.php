<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidDecisionDraftException;
use Koh\CrowdsecBridge\Domain\ValueObject\Reason;
use PHPUnit\Framework\TestCase;

final class ReasonTest extends TestCase
{
    public function testAcceptsExactly5Characters(): void
    {
        $reason = new Reason('abcde');
        self::assertSame('abcde', $reason->value);
    }

    public function testAcceptsExactly1000Characters(): void
    {
        $reason = new Reason(str_repeat('x', 1000));
        self::assertSame(1000, \strlen($reason->value));
    }

    public function testRejectsShorterThan5Characters(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new Reason('abcd');
    }

    public function testRejectsLongerThan1000Characters(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new Reason(str_repeat('x', 1001));
    }

    public function testTrimsWhitespace(): void
    {
        $reason = new Reason('   smoke-test    ');
        self::assertSame('smoke-test', $reason->value);
    }

    public function testWhitespaceOnlyIsRejected(): void
    {
        $this->expectException(InvalidDecisionDraftException::class);
        new Reason('         ');
    }
}
