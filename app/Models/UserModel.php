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
     * Find the global user by normalized email or create a minimal record.
     *
     * Email must already be normalized (lowercased, trimmed) before calling.
     * Returns the user ID, or null if the DB is in an unrecoverable state.
     */
    public function findOrCreateByEmail(string $email): ?int
    {
        $emailHash = hash('sha256', $email);

        $existing = $this->findByEmailHash($emailHash);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            $this->skipValidation(true);
            $inserted = $this->insert([
                'email'      => $email,
                'email_hash' => $emailHash,
                'first_name' => '',
                'last_name'  => '',
                'phone'      => '',
            ]);

            if ($inserted !== false) {
                return (int) $inserted;
            }
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // Concurrent insert raced on the unique key — fall through to re-read.
            $msg = $e->getMessage();
            $isDuplicate = $e->getCode() === 1062 || $e->getCode() === 23505 || str_contains($msg, 'Duplicate entry') || str_contains($msg, 'UNIQUE constraint failed');
            if ($isDuplicate && str_contains(strtolower($msg), 'email')) {
                log_message('info', 'UserModel: concurrent email insert race, reusing existing row');
            } else {
                throw $e;
            }
        } finally {
            $this->skipValidation(false);
        }

        $existing = $this->findByEmailHash($emailHash);

        return $existing !== null ? (int) $existing['id'] : null;
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
