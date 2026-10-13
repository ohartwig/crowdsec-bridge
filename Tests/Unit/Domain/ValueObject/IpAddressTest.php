<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Tests\Unit\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidIpAddressException;
use Koh\CrowdsecBridge\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function testAcceptsIpv4(): void
    {
        $ip = new IpAddress('203.0.113.42');
        self::assertSame('203.0.113.42', $ip->value);
        self::assertSame(4, $ip->version);
    }

    public function testAcceptsIpv6(): void
    {
        $ip = new IpAddress('2001:db8::1');
        self::assertSame('2001:db8::1', $ip->value);
        self::assertSame(6, $ip->version);
    }

    public function testNormalisesAbbreviatedIpv6(): void
    {
        $compact = new IpAddress('::1');
        $expanded = new IpAddress('0:0:0:0:0:0:0:1');
        self::assertTrue($compact->equals($expanded));
        self::assertSame($compact->value, $expanded->value);
    }

    public function testTrimsWhitespace(): void
    {
        $ip = new IpAddress('  203.0.113.42  ');
        self::assertSame('203.0.113.42', $ip->value);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'plain text' => ['foobar'];
        yield 'partial' => ['203.0'];
        yield 'out of range' => ['999.999.999.999'];
        yield 'empty' => [''];
        yield 'mixed garbage' => ['203.0.113.42-extra'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalid(string $input): void
    {
        $this->expectException(InvalidIpAddressException::class);
        new IpAddress($input);
    }
}
