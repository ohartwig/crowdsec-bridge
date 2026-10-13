<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

final class InvalidIpAddressException extends \InvalidArgumentException
{
    public static function notAnIp(string $value): self
    {
        return new self(\sprintf('Value "%s" is not a valid IPv4 or IPv6 address', $value));
    }
}
