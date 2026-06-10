<?php

namespace App\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;

class SlotModel extends Model
{
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
     */
    public function findForCapacityCheck(int $slotId, ConnectionInterface $db): ?array
    {
        $table = $db->prefixTable('slots');
        $lock  = (property_exists($db, 'DBDriver') && $db->DBDriver === 'MySQLi') ? ' FOR UPDATE' : '';

        $result = $db->query(
            "SELECT id, capacity, starts_at, ends_at FROM {$table} WHERE id = ?{$lock}",
            [$slotId],
        );

        return ($result ? $result->getRowArray() : null) ?: null;
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
            ->where('status', 'active')
            ->orderBy('stand_id', 'ASC')
            ->orderBy('starts_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}
