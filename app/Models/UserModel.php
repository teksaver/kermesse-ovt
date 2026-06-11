<?php

namespace App\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

/**
 * Global user identity — one record per unique email address across all kermesses.
 * Replaces the legacy OwnerModel and VolunteerModel (per-kermesse records).
 */
class UserModel extends Model
{
    use LockingReadsTrait;

    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'email',
        'email_hash',
        'first_name',
        'last_name',
        'phone',
    ];

    /**
     * Find a user by normalized (lowercased, trimmed) email hash.
     *
     * Pass $db to run on an open transaction connection.
     * Pass $lockForUpdate = true to acquire a row lock for race-safe operations.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailHash(
        string $emailHash,
        ?ConnectionInterface $db = null,
        bool $lockForUpdate = false,
    ): ?array {
        $conn  = $db ?? $this->db;
        $table = $conn->prefixTable('users');
        $lock  = $lockForUpdate ? $this->forUpdateSuffix($conn) : '';

        $result = $conn->query(
            "SELECT * FROM {$table} WHERE email_hash = ? LIMIT 1{$lock}",
            [$emailHash],
        );

        if ($result === false) {
            throw new DatabaseException('User lookup failed; refusing to continue (fail-closed).');
        }

        return $result->getRowArray() ?: null;
    }

    /**
     * Lock the user row to serialize concurrent overlap checks.
     */
    public function lockForOverlapCheck(int $userId, ConnectionInterface $db): void
    {
        $table = $db->prefixTable('users');
        $db->query("SELECT id FROM {$table} WHERE id = ?" . $this->forUpdateSuffix($db), [$userId]);
    }
}
