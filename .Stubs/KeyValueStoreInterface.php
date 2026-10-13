<?php

declare(strict_types=1);

/*
 * Test/PHPStan stub for the optional moselwal/keyvalue-store interface.
 *
 * When moselwal/keyvalue-store IS installed: composer autoloads the real interface and
 * the interface_exists() guard below makes this stub a no-op.
 *
 * When moselwal/keyvalue-store is NOT installed: this stub defines the interface so the
 * cache infrastructure classes can be loaded and unit-tested. It is loaded via the
 * composer.json autoload-dev "files" entry — production runtime never sees it.
 *
 * The shape mirrors the consumer contract documented in
 * specs/004-decision-cache/contracts/keyvalue-store-interface.md.
 */

namespace Moselwal\KeyValueStore;

if (!interface_exists(KeyValueStoreInterface::class, autoload: false)) {
    interface KeyValueStoreInterface
    {
        public function get(string $key): mixed;

        public function set(string $key, mixed $value, ?int $ttl = null): void;

        public function delete(string $key): void;

        public function has(string $key): bool;

        public function increment(string $key): int;
    }
}
