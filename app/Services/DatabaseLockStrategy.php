<?php

namespace App\Services;

/**
 * Strategy interface for database-level named locks.
 *
 * MariaDB/MySQL provides GET_LOCK() / RELEASE_LOCK() for cross-session
 * advisory locks. Other drivers (e.g. SQLite in tests) do not support
 * this feature. This interface abstracts the locking mechanism so that
 * services can be tested without a MySQL connection.
 */
interface DatabaseLockStrategy
{
    /**
     * Acquire a named lock.
     *
     * @param string $name    The lock name.
     * @param int    $timeout Seconds to wait for the lock (0 = non-blocking).
     *
     * @return bool True if the lock was acquired.
     */
    public function acquire(string $name, int $timeout): bool;

    /**
     * Release a previously acquired named lock.
     *
     * @param string $name The lock name.
     */
    public function release(string $name): void;
}
