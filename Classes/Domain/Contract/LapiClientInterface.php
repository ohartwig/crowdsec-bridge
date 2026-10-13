<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Domain\Contract;

use Koh\CrowdsecBridge\Domain\Exception\LapiAuthenticationException;
use Koh\CrowdsecBridge\Domain\Exception\LapiUnavailableException;

/**
 * Thin transport contract for the CrowdSec Local API.
 * Implementations MUST translate transport-level failures (timeouts, 5xx)
 * into LapiUnavailableException and 401 into LapiAuthenticationException.
 */
interface LapiClientInterface
{
    /**
     * @param array<string, scalar> $query
     *
     * @return array<int|string, mixed> decoded JSON body (object → assoc array, array → list)
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function get(string $path, array $query = []): array;

    /**
     * @param array<int|string, mixed> $body will be JSON-encoded
     *
     * @return array<int|string, mixed> decoded JSON response body
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function post(string $path, array $body): array;

    /**
     * @return bool true on HTTP 200 (deletion succeeded), false on HTTP 404 (already gone)
     *
     * @throws LapiUnavailableException
     * @throws LapiAuthenticationException
     */
    public function delete(string $path): bool;
}
