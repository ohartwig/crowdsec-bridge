<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\ValueObject;

enum DecisionType: string
{
    case Ban = 'ban';
    case Captcha = 'captcha';

    public static function fromLapi(string $value): self
    {
        return self::tryFrom($value) ?? throw new \ValueError(\sprintf('Unknown LAPI decision type "%s"', $value));
    }
}
