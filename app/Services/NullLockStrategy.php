<?php

namespace App\Services;

/**
 * No-op lock strategy for drivers that lack advisory named locks (e.g. SQLite).
 *
 * acquire() always returns true and release() is a no-op. This allows
 * services that depend on DatabaseLockStrategy to run in test environments
 * without a MariaDB connection, while still exercising the full service logic.
 *
 * Concurrency protection is a production concern handled by MariaDBLockStrategy;
 * single-threaded test transactions provide sufficient isolation in SQLite.
 */
class NullLockStrategy implements DatabaseLockStrategy
{
    public function acquire(string $name, int $timeout): bool
    {
        return true;
    }

    public function release(string $name): void
    {
        // No-op: SQLite and other non-MySQL drivers have no named locks.
    }
}
