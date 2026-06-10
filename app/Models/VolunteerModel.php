<?php

namespace App\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

class VolunteerModel extends Model
{
    use LockingReadsTrait;

    protected $table      = 'volunteers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'kermesse_id',
        'first_name',
        'last_name',
        'email',
        'phone',
    ];

    /**
     * Find a volunteer by kermesse and normalized (lowercased, trimmed) email.
     *
     * Pass $db to run on the signup transaction's connection. With $lockForUpdate the
     * read is a locking read that sees the latest committed row — required by the
     * insert-race fallback, where a plain read would hit the transaction's stale
     * snapshot and miss the volunteer a concurrent request just committed.
     *
     * @return array<string, mixed>|null
     */
    public function findByKermesseAndEmail(
        int $kermesseId,
        string $normalizedEmail,
        ?ConnectionInterface $db = null,
        bool $lockForUpdate = false,
    ): ?array {
        $conn  = $db ?? $this->db;
        $table = $conn->prefixTable('volunteers');
        $lock  = $lockForUpdate ? $this->forUpdateSuffix($conn) : '';

        $result = $conn->query(
            "SELECT * FROM {$table} WHERE kermesse_id = ? AND email = ? LIMIT 1{$lock}",
            [$kermesseId, $normalizedEmail],
        );

        if ($result === false) {
            throw new DatabaseException('Volunteer lookup failed; refusing to continue (fail-closed).');
        }

        return $result->getRowArray() ?: null;
    }

    /**
     * Lock the volunteer row to serialize concurrent overlap checks.
     */
    public function lockForOverlapCheck(int $volunteerId, ConnectionInterface $db): void
    {
        $table = $db->prefixTable('volunteers');
        $db->query("SELECT id FROM {$table} WHERE id = ?" . $this->forUpdateSuffix($db), [$volunteerId]);
    }
}
