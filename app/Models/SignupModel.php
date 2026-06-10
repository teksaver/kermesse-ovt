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
        'volunteer_id',
        'status',
        'deleted_at',
    ];

    /**
     * Return the active signup count for a single slot.
     */
    public function countActiveForSlot(int $slotId): int
    {
        return $this->countActiveBySlotIds([$slotId])[$slotId] ?? 0;
    }

    /**
     * Return an active signup for the given volunteer on the given slot, or null.
     *
     * Used for duplicate-signup detection inside a transaction.
     */
    public function findActiveByVolunteerAndSlot(int $volunteerId, int $slotId): ?array
    {
        $row = $this->where('volunteer_id', $volunteerId)
            ->where('slot_id', $slotId)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->first();

        return $row ?: null;
    }

    /**
     * Return the first active signup whose slot overlaps [$startsAt, $endsAt) for
     * the given volunteer, excluding $excludeSlotId (the target slot).
     *
     * Two intervals overlap when: existing.starts_at < endsAt AND existing.ends_at > startsAt.
     * Used for overlap detection inside a transaction.
     */
    public function findOverlappingActiveByVolunteer(
        int    $volunteerId,
        string $startsAt,
        string $endsAt,
        int    $excludeSlotId,
    ): ?array {
        $db      = db_connect();
        $tSign   = $db->prefixTable('signups');
        $tSlots  = $db->prefixTable('slots');
        $inact   = implode(', ', array_fill(0, count(self::INACTIVE_STATUSES), '?'));

        $params = array_merge(
            [$volunteerId, $excludeSlotId],
            self::INACTIVE_STATUSES,
            [$endsAt, $startsAt]
        );

        $result = $db->query(
            "SELECT {$tSign}.id, {$tSlots}.starts_at, {$tSlots}.ends_at
             FROM {$tSign}
             JOIN {$tSlots} ON {$tSlots}.id = {$tSign}.slot_id
             WHERE {$tSign}.volunteer_id = ?
               AND {$tSign}.slot_id != ?
               AND {$tSign}.status NOT IN ({$inact})
               AND {$tSign}.deleted_at IS NULL
               AND {$tSlots}.starts_at < ?
               AND {$tSlots}.ends_at > ?
             LIMIT 1",
            $params,
        );

        if (! $result) {
            return null;
        }

        return $result->getRowArray() ?: null;
    }

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
