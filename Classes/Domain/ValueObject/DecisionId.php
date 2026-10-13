<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

final readonly class DecisionId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(\sprintf('Decision ID must be > 0, got %d', $value));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function toString(): string
    {
        return (string) $this->value;
    }
}
