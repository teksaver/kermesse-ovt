<?php

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Model;

class SlotModel extends Model
{
    use LockingReadsTrait;

    public const STATUS_ACTIVE      = 'active';
    public const STATUS_DEACTIVATED = 'deactivated';

    protected $table      = 'slots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'stand_id',
        'starts_at',
        'ends_at',
        'capacity',
        'status',
    ];

    /**
     * Return the slot row locked for update (FOR UPDATE serializes concurrent capacity checks
     * in MariaDB InnoDB). SQLite does not support locking reads, so FOR UPDATE is omitted on
     * that driver — the transaction still provides isolation in the test environment.
     *
     * Must be called inside an open transaction so the lock is held until commit/rollback.
     *
     * @return array<string, mixed>|null
     */
    public function findForCapacityCheck(int $slotId, BaseConnection $db): ?array
    {
        $table = $db->prefixTable('slots');

        $result = $db->query(
            "SELECT id, stand_id, capacity, status, starts_at, ends_at FROM {$table} WHERE id = ?" . $this->forUpdateSuffix($db),
            [$slotId],
        );

        if ($result === false) {
            // CI4 silences query errors inside transactions (transDepth > 0, transException off).
            // A lock wait timeout returns false here rather than throwing — surface it explicitly
            // so SlotSignupService::signup() catch block maps it to transaction_failed.
            throw new \CodeIgniter\Database\Exceptions\DatabaseException(
                'findForCapacityCheck query failed: ' . ($db->error()['message'] ?? 'unknown')
            );
        }

        return $result->getRowArray() ?: null;
    }

    /**
     * Return active slots for a list of stand IDs, sorted by stand then start time.
     *
     * @param int[] $standIds
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForStandIds(array $standIds): array
    {
        if (empty($standIds)) {
            return [];
        }

        return $this->whereIn('stand_id', $standIds)
            ->where('status', self::STATUS_ACTIVE)
            ->orderBy('stand_id', 'ASC')
            ->orderBy('starts_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}
