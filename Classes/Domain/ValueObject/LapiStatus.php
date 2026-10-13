<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class LapiStatus
{
    public function __construct(
        public bool $isReachable,
        public ?string $version,
        public ?string $bouncerName,
        public \DateTimeImmutable $checkedAt,
        public ?string $lastError,
    ) {}

    public static function reachable(string $version, ?string $bouncerName, \DateTimeImmutable $at): self
    {
        return new self(true, $version, $bouncerName, $at, null);
    }

    public static function unreachable(string $errorCode, \DateTimeImmutable $at): self
    {
        return new self(false, null, null, $at, $errorCode);
    }
}
