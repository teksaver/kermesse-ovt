<?php

namespace App\Models;

use CodeIgniter\Model;

class SignupModel extends Model
{
    protected $table      = 'signups';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    public const STATUS_CANCELLED   = 'cancelled';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_DELETED     = 'deleted';

    public const INACTIVE_STATUSES = [
        self::STATUS_CANCELLED,
        self::STATUS_DEACTIVATED,
        self::STATUS_DELETED,
    ];

    protected $allowedFields = [
        'slot_id',
        'volunteer_name',
        'status',
        'deleted_at',
    ];

    /**
     * Return active signup counts keyed by slot ID.
     *
     * Active = status NOT IN ('cancelled','deactivated','deleted') AND deleted_at IS NULL.
     * Same definition as StandDeletionService::applyActiveSignupFilter so public
     * availability and admin planning never diverge.
     *
     * @param int[] $slotIds
     * @return array<int, int>  slot_id => count
     */
    public function countActiveBySlotIds(array $slotIds): array
    {
        if (empty($slotIds)) {
            return [];
        }

        $rows = $this->select('slot_id, COUNT(*) AS cnt')
            ->whereIn('slot_id', $slotIds)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->groupBy('slot_id')
            ->findAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['slot_id']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
