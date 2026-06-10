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
        'volunteer_id',
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
             WHERE slot_id = ? AND status NOT IN ({$inact}) AND deleted_at IS NULL",
            array_merge([$slotId], self::INACTIVE_STATUSES),
        );

        if ($result === false) {
            throw new DatabaseException('Active-signup count failed; refusing to continue (fail-closed).');
        }

        return (int) ($result->getRowArray()['cnt'] ?? 0);
    }

    /**
     * Return an active signup for the given volunteer on the given slot, or null.
     *
     * Used for duplicate-signup detection inside a transaction. On MySQLi this is a
     * locking read: it must see signups committed by concurrent transactions after
     * this transaction's snapshot (the volunteer-row lock alone does not refresh
     * plain reads under REPEATABLE READ).
     */
    public function findActiveByVolunteerAndSlot(int $volunteerId, int $slotId, ?ConnectionInterface $db = null): ?array
    {
        $conn  = $db ?? $this->db;
        $table = $conn->prefixTable('signups');
        $inact = implode(', ', array_fill(0, count(self::INACTIVE_STATUSES), '?'));

        $result = $conn->query(
            "SELECT id, status FROM {$table}
             WHERE volunteer_id = ? AND slot_id = ?
               AND status NOT IN ({$inact}) AND deleted_at IS NULL
             LIMIT 1" . $this->forUpdateSuffix($conn),
            array_merge([$volunteerId, $slotId], self::INACTIVE_STATUSES),
        );

        if ($result === false) {
            throw new DatabaseException('Duplicate-signup check failed; refusing to continue (fail-closed).');
        }

        return $result->getRowArray() ?: null;
    }

    /**
     * Return the first active signup whose slot overlaps [$startsAt, $endsAt) for
     * the given volunteer, excluding $excludeSlotId (the target slot).
     *
     * Two intervals overlap when: existing.starts_at < endsAt AND existing.ends_at > startsAt.
     * Used for overlap detection inside a transaction. On MySQLi this is a locking read
     * for the same snapshot-freshness reason as findActiveByVolunteerAndSlot.
     */
    public function findOverlappingActiveByVolunteer(
        int    $volunteerId,
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
            [$volunteerId, $excludeSlotId],
            self::INACTIVE_STATUSES,
            [$endsAt, $startsAt]
        );

        $result = $conn->query(
            "SELECT {$tSign}.id, {$tSlots}.starts_at, {$tSlots}.ends_at
             FROM {$tSign}
             JOIN {$tSlots} ON {$tSlots}.id = {$tSign}.slot_id
             WHERE {$tSign}.volunteer_id = ?
               AND {$tSign}.slot_id != ?
               AND {$tSign}.status NOT IN ({$inact})
               AND {$tSign}.deleted_at IS NULL
               AND {$tSlots}.starts_at < ?
               AND {$tSlots}.ends_at > ?
             LIMIT 1" . $this->forUpdateSuffix($conn),
            $params,
        );

        if ($result === false) {
            // Fail closed: a failed check must never be read as "no overlap".
            throw new DatabaseException('Overlap check failed; refusing to continue (fail-closed).');
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
