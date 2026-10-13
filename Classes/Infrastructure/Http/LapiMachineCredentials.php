<?php

declare(strict_types=1);

namespace Koh\CrowdsecBridge\Infrastructure\Http;

/**
 * The login pair of a CrowdSec machine (watcher) account.
 *
 * Deliberately without a __toString(): the password must not be able to reach a
 * log line, an exception message or a template by accident.
 */
final readonly class LapiMachineCredentials
{
    public function __construct(
        public string $machineId,
        public string $password,
    ) {
        if ('' === $machineId || '' === $password) {
            throw new \InvalidArgumentException('A machine account needs both an id and a password.');
        }
    }
}
