<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * MariaDB/MySQL named-lock implementation using GET_LOCK() / RELEASE_LOCK().
 *
 * GET_LOCK() acquires an advisory lock visible to the entire server instance.
 * It serialises concurrent callers on the same lock name, which is used by
 * MigrationRunnerService and ReleaseActivationService to prevent parallel
 * deployment operations.
 */
class MariaDBLockStrategy implements DatabaseLockStrategy
{
    private BaseConnection $db;

    public function __construct(BaseConnection $db)
    {
        $this->db = $db;
    }

    public function acquire(string $name, int $timeout): bool
    {
        $result = $this->db->query(
            'SELECT GET_LOCK(?, ?) AS `acquired`',
            [$name, $timeout]
        )->getRowArray();

        return ($result['acquired'] ?? 0) == 1;
    }

    public function release(string $name): void
    {
        $this->db->query(
            'SELECT RELEASE_LOCK(?) AS `released`',
            [$name]
        );
    }
}
