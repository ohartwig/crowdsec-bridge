<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Exception;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;

final class AlertNotFoundException extends \RuntimeException
{
    public static function forId(AlertId $id): self
    {
        return new self(\sprintf('Alert with id %d not found in LAPI', $id->toInt()));
    }
}
