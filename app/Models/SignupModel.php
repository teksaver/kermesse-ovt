<?php

namespace App\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

class SignupModel extends Model
{
    use LockingReadsTrait;

    protected $table      = 'signups';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    public const STATUS_ACTIVE      = 'active';
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
        'user_id',
        'status',
        'deleted_at',
    ];

    /**
     * Return the active signup count for a single slot.
     *
     * Pass $db to run on the signup transaction's connection — the count is correct
     * under concurrency only because the slot row is locked FOR UPDATE before this
     * first plain read establishes the transaction snapshot.
     */
    public function countActiveForSlot(int $slotId, ?ConnectionInterface $db = null): int
    {
        if ($db === null) {
            return $this->countActiveBySlotIds([$slotId])[$slotId] ?? 0;
        }

        $table = $db->prefixTable('signups');
        $inact = implode(', ', array_fill(0, count(self::INACTIVE_STATUSES), '?'));

        $result = $db->query(
            "SELECT COUNT(*) AS cnt FROM {$table}
             WHERE slot_id = ? AND status NOT IN ({$inact}) AND deleted_at IS NULL" . $this->forUpdateSuffix($db),
            array_merge([$slotId], self::INACTIVE_STATUSES),
        );

        if ($result === false) {
            throw new DatabaseException('Active-signup count failed; refusing to continue (fail-closed).');
        }

        return (int) ($result->getRowArray()['cnt'] ?? 0);
    }

    /**
     * Return an active signup for the given user on the given slot, or null.
     *
     * Used for duplicate-signup detection inside a transaction. On MySQLi this is a
     * locking read: it must see signups committed by concurrent transactions after
     * this transaction's snapshot (the user-row lock alone does not refresh
     * plain reads under REPEATABLE READ).
     */
    public function findActiveByUserAndSlot(int $userId, int $slotId, ?ConnectionInterface $db = null): ?array
    {
        $conn  = $db ?? $this->db;
        $table = $conn->prefixTable('signups');
        $inact = implode(', ', array_fill(0, count(self::INACTIVE_STATUSES), '?'));

        $result = $conn->query(
            "SELECT id, status FROM {$table}
             WHERE user_id = ? AND slot_id = ?
               AND status NOT IN ({$inact}) AND deleted_at IS NULL
             LIMIT 1" . $this->forUpdateSuffix($conn),
            array_merge([$userId, $slotId], self::INACTIVE_STATUSES),
        );

        if ($result === false) {
            throw new DatabaseException('Duplicate-signup check failed; refusing to continue (fail-closed).');
        }

        return $result->getRowArray() ?: null;
    }

    /**
     * Return the first active signup whose slot overlaps [$startsAt, $endsAt) for
     * the given user, excluding $excludeSlotId (the target slot).
     *
     * Two intervals overlap when: existing.starts_at < endsAt AND existing.ends_at > startsAt.
     * Used for overlap detection inside a transaction. On MySQLi this is a locking read
     * for the same snapshot-freshness reason as findActiveByUserAndSlot.
     */
    public function findOverlappingActiveByUser(
        int    $userId,
        string $startsAt,
        string $endsAt,
        int    $excludeSlotId,
        ?ConnectionInterface $db = null,
    ): ?array {
        $conn    = $db ?? $this->db;
        $tSign   = $conn->prefixTable('signups');
        $tSlots  = $conn->prefixTable('slots');
        $inact   = implode(', ', array_fill(0, count(self::INACTIVE_STATUSES), '?'));

        $params = array_merge(
            [$userId, $excludeSlotId],
            self::INACTIVE_STATUSES,
            [$endsAt, $startsAt]
        );

        $signups = $conn->query(
            "SELECT id, slot_id FROM {$tSign}
             WHERE user_id = ? AND slot_id != ? AND status NOT IN ({$inact}) AND deleted_at IS NULL" . $this->forUpdateSuffix($conn),
            array_merge([$userId, $excludeSlotId], self::INACTIVE_STATUSES)
        )->getResultArray();

        if (empty($signups)) {
            return null;
        }

        $slotIds = array_column($signups, 'slot_id');
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        
        $overlaps = $conn->query(
            "SELECT id, starts_at, ends_at FROM {$tSlots}
             WHERE id IN ({$placeholders}) AND starts_at < ? AND ends_at > ?
             LIMIT 1",
            array_merge($slotIds, [$endsAt, $startsAt])
        )->getRowArray();

        if ($overlaps) {
            foreach ($signups as $s) {
                if ($s['slot_id'] == $overlaps['id']) {
                    return ['id' => $s['id'], 'starts_at' => $overlaps['starts_at'], 'ends_at' => $overlaps['ends_at']];
                }
            }
        }
        return null;
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

    /**
     * Return a connected user's ACTIVE signups for one kermesse, joined to the slot
     * and stand for the dashboard "Mes participations" section (Story 4.2).
     *
     * Active = status NOT IN ('cancelled','deactivated','deleted') AND deleted_at IS NULL —
     * the SAME definition as countActiveBySlotIds(), so a cancelled inscription the public
     * availability already treats as freed never reappears here (UX-DR23). Scoped to the
     * single user (privacy boundary) and ordered chronologically by slot start.
     *
     * Filtering on signup status alone is sufficient (no slot/stand status filter needed):
     * deactivating a stand or a slot cascades to its signups in the same transaction
     * (StandDeletionService / SlotDeletionService set them to 'deactivated'), so an active
     * signup can never point at a removed slot/stand. The signup status is the single
     * source of truth for "active" across the whole codebase.
     *
     * @return list<array{stand_name: string, starts_at: string, ends_at: string}>
     */
    public function findActiveForUserAndKermesse(int $userId, int $kermesseId): array
    {
        return $this->db->table($this->table . ' si')
            ->select('st.name AS stand_name, sl.starts_at, sl.ends_at')
            ->join('slots sl', 'sl.id = si.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->where('si.user_id', $userId)
            ->where('st.kermesse_id', $kermesseId)
            ->whereNotIn('si.status', self::INACTIVE_STATUSES)
            ->where('si.deleted_at', null)
            ->orderBy('sl.starts_at', 'ASC')
            ->orderBy('sl.id', 'ASC')
            ->get()
            ->getResultArray();
    }
}
