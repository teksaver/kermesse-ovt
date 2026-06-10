<?php

namespace App\Models;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Shared FOR UPDATE suffix for locking reads inside signup transactions.
 *
 * Locking reads bypass the InnoDB REPEATABLE READ snapshot and return the latest
 * committed row state — the signup invariants (capacity, duplicate, overlap)
 * rely on this to see rows committed by concurrent transactions after this
 * transaction's snapshot was established.
 *
 * SQLite (test driver) has no locking reads; the suffix is empty there and
 * single-threaded test transactions still isolate.
 */
trait LockingReadsTrait
{
    private function forUpdateSuffix(ConnectionInterface $db): string
    {
        return (($db->DBDriver ?? null) === 'MySQLi') ? ' FOR UPDATE' : '';
    }
}
