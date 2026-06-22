<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;

class SlotSignupModel extends Model
{
    use LockingReadsTrait;

    protected $table      = 'slot_signups';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'slot_id',
        'user_id',
        'created_by',
        'deleted_at',
        'last_modified_by_user_id',
        'last_modified_at',
        'first_name',
        'last_name',
        'email',
        'phone',
        'admin_notes',
        'viewed_at',
        'accepted_at',
        'rejected_at',
        'canceled_at',
        'canceled_by',
    ];

    /**
     * Delegates to SlotSignup::computeStatus() — logic lives in the entity.
     * Kept as a static wrapper for callers that work with raw row arrays.
     *
     * @param array<string, mixed> $row
     */
    public static function getStatus(array $row): string
    {
        return \App\Entities\SlotSignup::computeStatus($row);
    }

    /**
     * Returns true when the slot-signup requires the volunteer's explicit confirmation:
     * created by someone else (admin) and not yet accepted.
     * Used to decide whether to show Accept/Refuse buttons vs the Cancel button.
     *
     * @param array<string, mixed> $row
     */
    public static function needsConfirmation(array $row, int $userId): bool
    {
        $confirmedAt = $row['accepted_at'] ?? null;
        $createdBy   = isset($row['created_by']) ? (int) $row['created_by'] : null;
        return $confirmedAt === null
            && ($createdBy === null || $createdBy !== $userId);
    }

    /**
     * SQL fragment that evaluates to true when a slot-signup is "active" (counts toward
     * capacity and is visible). Alias for the WHERE clause in raw queries.
     *
     * Active = not canceled, not refused, not soft-deleted.
     */
    public const ACTIVE_CONDITION = 'canceled_at IS NULL AND rejected_at IS NULL AND deleted_at IS NULL';

    /**
     * Return the active slot-signup count for a single slot.
     *
     * Pass $db to run on the signup transaction's connection — the count is correct
     * under concurrency only because the slot row is locked FOR UPDATE before this
     * first plain read establishes the transaction snapshot.
     */
    public function countActiveForSlot(int $slotId, ?BaseConnection $db = null): int
    {
        if ($db === null) {
            return $this->countActiveBySlotIds([$slotId])[$slotId] ?? 0;
        }

        $table = $db->prefixTable($this->table);

        $result = $db->query(
            "SELECT COUNT(*) AS cnt FROM {$table}
             WHERE slot_id = ? AND " . self::ACTIVE_CONDITION . $this->forUpdateSuffix($db),
            [$slotId],
        );

        if ($result === false) {
            throw new DatabaseException('Active-signup count failed; refusing to continue (fail-closed).');
        }

        return (int) ($result->getRowArray()['cnt'] ?? 0);
    }

    /**
     * Return an active slot-signup for the given user on the given slot, or null.
     *
     * Used for duplicate-signup detection inside a transaction.
     */
    /** @return array<string, mixed>|null */
    public function findActiveByEmailOrUserAndSlot(string $email, ?int $userId, int $slotId, ?BaseConnection $db = null): ?array
    {
        $conn  = $db ?? $this->db;
        $table = $conn->prefixTable($this->table);

        $userCond = $userId !== null ? "OR user_id = ?" : "";
        $params = [$slotId, $email];
        if ($userId !== null) {
            $params[] = $userId;
        }

        $result = $conn->query(
            "SELECT id FROM {$table}
             WHERE slot_id = ? AND (email = ? {$userCond})
               AND " . self::ACTIVE_CONDITION . "
             LIMIT 1" . $this->forUpdateSuffix($conn),
            $params,
        );

        if ($result === false) {
            throw new DatabaseException('Duplicate-signup check failed; refusing to continue (fail-closed).');
        }

        return $result->getRowArray() ?: null;
    }

    /**
     * Return the first active slot-signup whose slot overlaps [$startsAt, $endsAt) for
     * the given user, excluding $excludeSlotId (the target slot).
     *
     * @return array<string, mixed>|null
     */
    public function findOverlappingActiveByEmailOrUser(
        string $email,
        ?int   $userId,
        string $startsAt,
        string $endsAt,
        int    $excludeSlotId,
        int    $kermesseId,
        ?BaseConnection $db = null,
    ): ?array {
        $conn    = $db ?? $this->db;
        $tSign   = $conn->prefixTable($this->table);
        $tSlots  = $conn->prefixTable('slots');
        $tStands = $conn->prefixTable('stands');

        $userCond = $userId !== null ? "OR si.user_id = ?" : "";
        $params = [$excludeSlotId, $kermesseId, $email];
        if ($userId !== null) {
            $params[] = $userId;
        }

        $signups = $conn->query(
            "SELECT si.id, si.slot_id FROM {$tSign} si
             JOIN {$tSlots} sl ON sl.id = si.slot_id
             JOIN {$tStands} st ON st.id = sl.stand_id
             WHERE si.slot_id != ? AND st.kermesse_id = ? AND (si.email = ? {$userCond})
               AND si.canceled_at IS NULL AND si.rejected_at IS NULL AND si.deleted_at IS NULL " . $this->forUpdateSuffix($conn),
            $params
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
     * Return active slot-signup counts keyed by slot ID.
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
            ->where('canceled_at', null)
            ->where('rejected_at', null)
            ->groupBy('slot_id')
            ->findAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['slot_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Return a connected user's ACTIVE slot-signups for one kermesse, joined to the slot
     * and stand for the dashboard "Mes participations" section (Story 4.2).
     *
     * Active = canceled_at IS NULL AND rejected_at IS NULL AND deleted_at IS NULL.
     *
     * @return list<array{signup_id: int, stand_name: string, starts_at: string, ends_at: string, accepted_at: string|null, created_by: int|null}>
     */
    public function findActiveForUserAndKermesse(int $userId, int $kermesseId): array
    {
        return $this->db->table($this->table . ' ss')
            ->select('ss.id AS signup_id, st.name AS stand_name, sl.starts_at, sl.ends_at, ss.accepted_at, ss.created_by')
            ->join('slots sl', 'sl.id = ss.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->where('st.kermesse_id', $kermesseId)
            ->where('ss.user_id', $userId)
            ->where('ss.canceled_at', null)
            ->where('ss.rejected_at', null)
            ->where('ss.deleted_at', null)
            ->orderBy('sl.starts_at', 'ASC')
            ->orderBy('sl.id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Return every ACTIVE slot-signup for a kermesse, joined to each volunteer's identity and
     * contact details, for the dashboard "Gestion des inscrits" section (Story 4.4/5.3/5.10/5.14).
     *
     * Story 5.14: LEFT JOIN users so orphan signups (user_id IS NULL) are included.
     * The display name falls back to signup snapshot fields when the status is 'unconfirmed'.
     *
     * PRIVACY (NFR5): this is the ONLY read that exposes volunteer PII.
     *
     * @return list<array{signup_id: int, slot_id: int, stand_id: int, user_id: int|null, accepted_at: string|null, created_by: int|null, first_name: string|null, last_name: string|null, phone: string|null, email: string|null, last_login_at: string|null, signup_first_name: string|null, signup_last_name: string|null, signup_email: string|null, signup_phone: string|null, admin_notes: string|null, first_access_at: string|null, modifier_first_name: string|null, last_modified_at: string|null}>
     */
    public function findActiveParticipantsForKermesse(int $kermesseId): array
    {
        $ss    = $this->db->prefixTable($this->table);
        $sl    = $this->db->prefixTable('slots');
        $st    = $this->db->prefixTable('stands');
        $u     = $this->db->prefixTable('users');
        $kur   = $this->db->prefixTable('kermesse_user_roles');

        $sql = "SELECT
                ss.id AS signup_id, ss.slot_id, sl.stand_id,
                ss.user_id, ss.accepted_at, ss.created_by,
                u.first_name, u.last_name, u.phone, u.email, u.last_login_at,
                ss.first_name  AS signup_first_name, ss.last_name AS signup_last_name,
                ss.email       AS signup_email,       ss.phone    AS signup_phone,
                ss.admin_notes,
                kur_agg.first_access_at,
                ss.last_modified_at, mod_u.first_name AS modifier_first_name
            FROM {$ss} ss
            JOIN {$sl} sl      ON sl.id = ss.slot_id
            JOIN {$st} st      ON st.id = sl.stand_id
            LEFT JOIN {$u}  u       ON u.id  = ss.user_id
            LEFT JOIN {$u}  mod_u ON mod_u.id = ss.last_modified_by_user_id
            LEFT JOIN (
                SELECT user_id, kermesse_id, MIN(first_access_at) AS first_access_at
                FROM {$kur}
                GROUP BY user_id, kermesse_id
            ) kur_agg ON kur_agg.user_id = ss.user_id AND kur_agg.kermesse_id = st.kermesse_id
            WHERE st.kermesse_id = ?
              AND ss.canceled_at IS NULL
              AND ss.rejected_at IS NULL
              AND ss.deleted_at IS NULL
            ORDER BY u.last_name ASC, u.first_name ASC, ss.id ASC";

        $result = $this->db->query($sql, [$kermesseId]);

        return $result ? $result->getResultArray() : [];
    }

    /**
     * Return historical (cancelled/removed/refused) slot-signups for a kermesse.
     *
     * Story 5.14: status is computed from timestamps. Cancelled = canceled_at set by
     * volunteer (canceled_by = user_id). Removed = canceled_at set by admin (canceled_by
     * != user_id or IS NULL). Refused = rejected_at set.
     *
     * @return list<array{signup_id: int, slot_id: int, stand_id: int, status: string, first_name: string|null, last_name: string|null, signup_first_name: string|null, signup_last_name: string|null, last_modified_at: string|null, modifier_first_name: string|null}>
     */
    public function findHistoricalParticipantsForKermesse(int $kermesseId): array
    {
        $ss  = $this->db->prefixTable($this->table);
        $sl  = $this->db->prefixTable('slots');
        $st  = $this->db->prefixTable('stands');
        $u   = $this->db->prefixTable('users');

        $sql = "SELECT
                ss.id AS signup_id, ss.slot_id, sl.stand_id,
                ss.canceled_at, ss.canceled_by, ss.rejected_at, ss.user_id,
                u.first_name, u.last_name,
                ss.first_name AS signup_first_name, ss.last_name AS signup_last_name,
                ss.last_modified_at, mod_u.first_name AS modifier_first_name
            FROM {$ss} ss
            JOIN {$sl} sl      ON sl.id = ss.slot_id
            JOIN {$st} st      ON st.id = sl.stand_id
            LEFT JOIN {$u}  u       ON u.id  = ss.user_id
            LEFT JOIN {$u}  mod_u ON mod_u.id = ss.last_modified_by_user_id
            WHERE st.kermesse_id = ?
              AND (ss.canceled_at IS NOT NULL OR ss.rejected_at IS NOT NULL)
              AND ss.deleted_at IS NULL
            ORDER BY ss.last_modified_at DESC, u.last_name ASC, ss.id ASC";

        $result = $this->db->query($sql, [$kermesseId]);

        if (! $result) {
            return [];
        }

        return array_map(static function (array $row): array {
            $row['status'] = self::getStatus($row);
            return $row;
        }, $result->getResultArray());
    }

    /**
     * Return an ACTIVE slot-signup that belongs to $kermesseId (via slot→stand scope),
     * with no user-ownership restriction — used by admin cancel and edit actions.
     *
     * @return array{id: int, user_id: int|null, slot_id: int, email: string|null, first_name: string|null, last_name: string|null, phone: string|null, signup_email: string|null, signup_first_name: string|null, signup_last_name: string|null, signup_phone: string|null, stand_name: string, starts_at: string, ends_at: string}|null
     */
    public function findActiveInKermesse(int $signupId, int $kermesseId): ?array
    {
        $row = $this->db->table($this->table . ' ss')
            ->select('ss.id, ss.user_id, ss.slot_id, u.email, u.first_name, u.last_name, u.phone, ss.email AS signup_email, ss.first_name AS signup_first_name, ss.last_name AS signup_last_name, ss.phone AS signup_phone, st.name AS stand_name, sl.starts_at, sl.ends_at', false)
            ->join('slots sl', 'sl.id = ss.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->join('users u', 'u.id = ss.user_id', 'left')
            ->where('ss.id', $signupId)
            ->where('st.kermesse_id', $kermesseId)
            ->where('ss.canceled_at', null)
            ->where('ss.rejected_at', null)
            ->where('ss.deleted_at', null)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Mark a slot-signup as cancelled by admin (sets canceled_at and canceled_by to admin).
     * Returns true when exactly one active row was updated.
     */
    public function markCancelledByAdmin(int $signupId, int $adminUserId): bool
    {
        $now = date('Y-m-d H:i:s');
        $this->builder()
            ->where('id', $signupId)
            ->where('canceled_at', null)
            ->where('rejected_at', null)
            ->where('deleted_at', null)
            ->update([
                'canceled_at' => $now,
                'canceled_by' => $adminUserId,
                'updated_at'  => $now,
            ]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Write the admin-editable contact fields to the slot_signups row (Story 5.10 AC2).
     *
     * @param array{first_name?: string, last_name?: string, email?: string, phone?: string, admin_notes?: string} $fields
     */
    public function updateContactFields(int $signupId, array $fields): bool
    {
        $allowed = array_intersect_key($fields, array_flip(['first_name', 'last_name', 'email', 'phone', 'admin_notes']));
        if ($allowed === []) {
            return false;
        }

        $allowed['updated_at'] = date('Y-m-d H:i:s');

        $this->builder()
            ->where('id', $signupId)
            ->where('deleted_at', null)
            ->update($allowed);

        return in_array($this->db->affectedRows(), [0, 1], true);
    }

    /**
     * Return an ACTIVE slot-signup that belongs to $userId AND to $kermesseId, or null.
     * Ownership + scope guard for volunteer self-cancellation (Story 4.3).
     *
     * @return array{id: int, user_id: int, slot_id: int, accepted_at: string|null}|null
     */
    public function findActiveOwnedInKermesse(int $signupId, int $userId, int $kermesseId): ?array
    {
        $userRow = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow();

        $builder = $this->db->table($this->table . ' ss')
            ->select('ss.id, ss.user_id, ss.slot_id, ss.accepted_at')
            ->join('slots sl', 'sl.id = ss.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->where('ss.id', $signupId)
            ->where('st.kermesse_id', $kermesseId)
            ->where('ss.canceled_at', null)
            ->where('ss.rejected_at', null)
            ->where('ss.deleted_at', null);

        if ($userRow !== null && $userRow->email !== '') {
            $builder->groupStart()
                ->where('ss.user_id', $userId)
                ->orWhere('ss.email', $userRow->email)
                ->groupEnd();
        } else {
            $builder->where('ss.user_id', $userId);
        }

        $row = $builder->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Find a slot-signup that is already rejected and owned by the given user in the given kermesse.
     * Used for idempotency in rejectSlotSignup(): a second POST after the slot was already freed
     * should return success rather than a confusing "not_found" error.
     *
     * @return array<string, mixed>|null
     */
    public function findRejectedOwnedInKermesse(int $signupId, int $userId, int $kermesseId): ?array
    {
        $userRow = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow();

        $builder = $this->db->table($this->table . ' ss')
            ->select('ss.id')
            ->join('slots sl', 'sl.id = ss.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->where('ss.id', $signupId)
            ->where('st.kermesse_id', $kermesseId)
            ->where('ss.rejected_at IS NOT NULL', null, false);

        if ($userRow !== null && $userRow->email !== '') {
            $builder->groupStart()
                ->where('ss.user_id', $userId)
                ->orWhere('ss.email', $userRow->email)
                ->groupEnd();
        } else {
            $builder->where('ss.user_id', $userId);
        }

        $row = $builder->get()->getRowArray();
        return $row ?: null;
    }

    /**
     * Stamp the modification-tracking columns — Story 5.1.
     */
    public function stampAdminModification(int $signupId, int $modifiedByUserId): bool
    {
        $this->builder()
            ->where('id', $signupId)
            ->where('deleted_at', null)
            ->update([
                'last_modified_by_user_id' => $modifiedByUserId,
                'last_modified_at'         => date('Y-m-d H:i:s'),
                'updated_at'               => date('Y-m-d H:i:s'),
            ]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Transition an ACTIVE slot-signup to CANCELLED by the volunteer (sets canceled_at,
     * canceled_by = userId). Returns true only when exactly one active row flipped.
     */
    public function markCancelled(int $signupId, int $userId): bool
    {
        $now = date('Y-m-d H:i:s');
        $this->builder()
            ->where('id', $signupId)
            ->where('user_id', $userId)
            ->where('canceled_at', null)
            ->where('rejected_at', null)
            ->where('deleted_at', null)
            ->update([
                'canceled_at' => $now,
                'canceled_by' => $userId,
                'updated_at'  => $now,
            ]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Mark a slot-signup as accepted (certified) by the volunteer (Story 5.14 AC3).
     * Sets accepted_at. Only applies when the signup has no accepted_at yet.
     */
    public function markAccepted(int $signupId, int $userId): bool
    {
        $userRow = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow();

        $builder = $this->builder()
            ->where('id', $signupId)
            ->where('accepted_at', null)
            ->where('canceled_at', null)
            ->where('rejected_at', null)
            ->where('deleted_at', null);

        // Match by user_id or email (orphan signups re-attached at login)
        if ($userRow !== null && $userRow->email !== '') {
            $builder->groupStart()
                ->where('user_id', $userId)
                ->orWhere('email', $userRow->email)
                ->groupEnd();
        } else {
            $builder->where('user_id', $userId);
        }

        $now = date('Y-m-d H:i:s');
        $builder->update([
            'accepted_at' => $now,
            'updated_at'  => $now,
        ]);

        // 0 rows = signup was already accepted (idempotent). Ownership was validated
        // upstream by findActiveOwnedInKermesse(), so 0 here means accepted_at was
        // already set — the action succeeded previously.
        $affected = $this->db->affectedRows();
        return $affected === 1 || $affected === 0;
    }

    /**
     * Mark a slot-signup as rejected by the volunteer (Story 5.14 AC4).
     * Sets rejected_at, which frees the slot capacity.
     * Requires accepted_at IS NULL — a certified signup cannot be rejected (P3).
     */
    public function markRejected(int $signupId, int $userId): bool
    {
        $userRow = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRow();

        $builder = $this->builder()
            ->where('id', $signupId)
            ->where('accepted_at', null) // P3: a certified signup cannot be rejected
            ->where('rejected_at', null)
            ->where('canceled_at', null)
            ->where('deleted_at', null);

        if ($userRow !== null && $userRow->email !== '') {
            $builder->groupStart()
                ->where('user_id', $userId)
                ->orWhere('email', $userRow->email)
                ->groupEnd();
        } else {
            $builder->where('user_id', $userId);
        }

        $now = date('Y-m-d H:i:s');
        $builder->update([
            'rejected_at' => $now,
            'updated_at'  => $now,
        ]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Attaches orphan slot-signups to a user and stamps viewed_at for those not yet seen.
     *
     * @return int Number of rows attached (viewed_at updated)
     */
    public function attachOrphansToUser(string $email, int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->where('email', strtolower(trim($email)))
             ->where('user_id', null)
             ->update(null, [
                 'user_id'    => $userId,
                 'viewed_at'  => $now,
                 'updated_at' => $now,
             ]);

        return $this->db->affectedRows();
    }

    /**
     * Return the distinct kermesse IDs for all active slot-signups belonging to a user.
     * Used by resolveOrphanSignups to insert benevole roles after orphan attachment.
     *
     * @return list<int>
     */
    public function findKermesseIdsForUser(int $userId): array
    {
        $si = $this->db->prefixTable($this->table);
        $sl = $this->db->prefixTable('slots');
        $st = $this->db->prefixTable('stands');

        $result = $this->db->query(
            "SELECT DISTINCT st.kermesse_id
             FROM {$si} si
             JOIN {$sl} sl ON sl.id = si.slot_id
             JOIN {$st} st ON st.id = sl.stand_id
             WHERE si.user_id = ? AND si.deleted_at IS NULL",
            [$userId]
        );

        return $result
            ? array_column($result->getResultArray(), 'kermesse_id')
            : [];
    }

    /**
     * Stamp viewed_at for existing unconfirmed slot-signups of a user that have not yet been seen.
     * Called at login for signups already attached to the user but not yet acknowledged (AC1).
     *
     * @return int Number of rows updated
     */
    public function stampViewedForUnconfirmedSignups(int $userId): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table($this->table)
            ->where('user_id', $userId)
            ->where('accepted_at', null)
            ->where('rejected_at', null)
            ->where('canceled_at', null)
            ->where('deleted_at', null)
            ->where('viewed_at', null)
            ->update([
                'viewed_at'  => $now,
                'updated_at' => $now,
            ]);

        return $this->db->affectedRows();
    }
}
