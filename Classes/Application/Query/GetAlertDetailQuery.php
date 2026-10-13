<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

use Koh\CrowdsecBridge\Domain\ValueObject\AlertId;

final readonly class GetAlertDetailQuery
{
    public function __construct(public AlertId $alertId) {}
}
