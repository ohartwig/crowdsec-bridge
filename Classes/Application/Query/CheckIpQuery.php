<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Application\Query;

final readonly class CheckIpQuery
{
    public function __construct(
        public string $ipString,
        public int $backendUserId,
        public string $backendUserName,
    ) {}
}
