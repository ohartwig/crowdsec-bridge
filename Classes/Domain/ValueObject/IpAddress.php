<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

use Koh\CrowdsecBridge\Domain\Exception\InvalidIpAddressException;

/**
 * Validated IPv4 or IPv6 address. IPv6 inputs are normalised via inet_pton/inet_ntop so that
 * "::1" and "0:0:0:0:0:0:0:1" compare equal.
 */
final readonly class IpAddress
{
    public string $value;
    public int $version;

    public function __construct(string $rawValue)
    {
        $trimmed = trim($rawValue);
        if (false === filter_var($trimmed, \FILTER_VALIDATE_IP)) {
            throw InvalidIpAddressException::notAnIp($rawValue);
        }

        $packed = inet_pton($trimmed);
        if (false === $packed) {
            throw InvalidIpAddressException::notAnIp($rawValue);
        }

        $normalised = inet_ntop($packed);
        if (false === $normalised) {
            throw InvalidIpAddressException::notAnIp($rawValue);
        }

        $this->value = $normalised;
        $this->version = false !== filter_var($normalised, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) ? 4 : 6;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
