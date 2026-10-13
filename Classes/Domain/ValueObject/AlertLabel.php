<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class AlertLabel
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
