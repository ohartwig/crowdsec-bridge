<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Cache;

use Psr\Log\LoggerInterface;

/**
 * Request-scoped flag tracker. Ensures FR-010: max. 1 warning log per backend
 * request when the cache backend (Redis/Valkey/etc.) becomes unreachable.
 *
 * NOT readonly — needs to flip its flags during the request lifecycle.
 * TYPO3 14 DI lifecycle instantiates services per request by default, which
 * gives this tracker the exact scope it needs.
 */
final class CacheBackendStateTracker
{
    private bool $backendDown = false;
    private bool $loggedWarning = false;

    public function __construct(private readonly LoggerInterface $logger) {}

    public function isBackendDown(): bool
    {
        return $this->backendDown;
    }

    public function markBackendDown(string $reason): void
    {
        $this->backendDown = true;
        if ($this->loggedWarning) {
            return;
        }
        $this->loggedWarning = true;
        $this->logger->warning(
            'CrowdSec Bridge cache backend unavailable; falling back to direct LAPI',
            ['reason' => $reason],
        );
    }
}
