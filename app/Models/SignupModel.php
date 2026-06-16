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
        'last_modified_by_user_id',
        'last_modified_at',
        'first_name',
        'last_name',
        'email',
        'phone',
        'admin_notes',
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
     * The signup id rides along so the dashboard can target the per-row cancel
     * action (Story 4.3) without a second query.
     *
     * @return list<array{signup_id: int, stand_name: string, starts_at: string, ends_at: string}>
     */
    public function findActiveForUserAndKermesse(int $userId, int $kermesseId): array
    {
        return $this->db->table($this->table . ' si')
            ->select('si.id AS signup_id, st.name AS stand_name, sl.starts_at, sl.ends_at')
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

    /**
     * Return every ACTIVE signup for a kermesse, joined to each volunteer's identity and
     * contact details, for the dashboard "Gestion des inscrits" section (Story 4.4/5.3/5.10).
     *
     * PRIVACY (NFR5): this is the ONLY read that exposes volunteer PII (first_name,
     * last_name, phone, email). Its result must never reach a public view — it is gated
     * behind the Owner/Admin/Gestionnaire role check in KermesseAdminController.
     *
     * Active = status NOT IN INACTIVE_STATUSES AND deleted_at IS NULL — the SAME
     * definition as countActiveBySlotIds() / findActiveForUserAndKermesse(), so the
     * occupied/remaining counts derived from this list match public availability exactly
     * (a cancelled inscription the public planning already freed never reappears here).
     *
     * Each row carries slot_id (to group volunteers under their slot) and stand_id;
     * rows are ordered by volunteer name for a stable nominative list. Empty slots simply
     * have no row — the controller overlays them onto the full slot list for the recap.
     *
     * Story 5.3: modifier_first_name (nullable) and last_modified_at (nullable) expose
     * who made the last admin correction, via a LEFT JOIN on users aliased as mod_u.
     *
     * Story 5.10: signup_id is exposed for cancel/edit actions. signup_first_name/
     * last_name/email/phone are the admin-editable copies on signups (NULL when never
     * corrected). first_access_at from kermesse_user_roles determines whether the
     * profile is locked (non-NULL) or editable (NULL) by the admin.
     *
     * Display rule: if signup_{field} IS NOT NULL → use signup copy; otherwise use user copy.
     * The controller applies this rule when building the view model.
     *
     * first_access_at is derived via MIN() over all roles for the user in this kermesse so
     * that the lock fires if the user has accessed in ANY capacity (handles multiple-role
     * edge cases) and never multiplies rows (replaces the prior O(N) correlated subquery).
     *
     * @return list<array{signup_id: int, slot_id: int, stand_id: int, first_name: string, last_name: string, phone: string, email: string, signup_first_name: string|null, signup_last_name: string|null, signup_email: string|null, signup_phone: string|null, first_access_at: string|null, modifier_first_name: string|null, last_modified_at: string|null}>
     */
    public function findActiveParticipantsForKermesse(int $kermesseId): array
    {
        $si    = $this->db->prefixTable('signups');
        $sl    = $this->db->prefixTable('slots');
        $st    = $this->db->prefixTable('stands');
        $u     = $this->db->prefixTable('users');
        $kur   = $this->db->prefixTable('kermesse_user_roles');
        $inact = implode(', ', array_fill(0, count(self::INACTIVE_STATUSES), '?'));

        // MIN(first_access_at) returns the earliest non-null access across all roles; returns
        // null only when ALL rows are null — which is the exact "not yet accessed" condition
        // needed for the admin-edit lock.  This replaces the prior per-row correlated subquery.
        $sql = "SELECT
                si.id AS signup_id, si.slot_id, sl.stand_id,
                u.first_name, u.last_name, u.phone, u.email, u.last_login_at,
                si.first_name  AS signup_first_name, si.last_name AS signup_last_name,
                si.email       AS signup_email,       si.phone    AS signup_phone,
                si.admin_notes,
                kur_agg.first_access_at,
                si.last_modified_at, mod_u.first_name AS modifier_first_name
            FROM {$si} si
            JOIN {$sl} sl      ON sl.id = si.slot_id
            JOIN {$st} st      ON st.id = sl.stand_id
            JOIN {$u}  u       ON u.id  = si.user_id
            LEFT JOIN {$u}  mod_u ON mod_u.id = si.last_modified_by_user_id
            LEFT JOIN (
                SELECT user_id, kermesse_id, MIN(first_access_at) AS first_access_at
                FROM {$kur}
                GROUP BY user_id, kermesse_id
            ) kur_agg ON kur_agg.user_id = si.user_id AND kur_agg.kermesse_id = st.kermesse_id
            WHERE st.kermesse_id = ?
              AND si.status NOT IN ({$inact})
              AND si.deleted_at IS NULL
            ORDER BY u.last_name ASC, u.first_name ASC, si.id ASC";

        $result = $this->db->query($sql, array_merge([$kermesseId], self::INACTIVE_STATUSES));

        return $result ? $result->getResultArray() : [];
    }

    /**
     * Return an ACTIVE signup that belongs to $kermesseId (via slot→stand scope),
     * with no user-ownership restriction — used by admin cancel and edit actions.
     *
     * A neutral miss (wrong kermesse, already inactive, soft-deleted) returns null.
     *
     * signup_email / signup_first_name are the admin-corrected copies from the signups
     * table (null when never edited). The service uses them to target cancellation emails
     * at the corrected address rather than the stale global profile.
     *
     * @return array{id: int, user_id: int, slot_id: int, email: string, first_name: string|null, last_name: string|null, signup_email: string|null, signup_first_name: string|null}|null
     */
    public function findActiveInKermesse(int $signupId, int $kermesseId): ?array
    {
        $row = $this->db->table($this->table . ' si')
            ->select('si.id, si.user_id, si.slot_id, u.email, u.first_name, u.last_name, si.email AS signup_email, si.first_name AS signup_first_name', false)
            ->join('slots sl', 'sl.id = si.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->join('users u', 'u.id = si.user_id')
            ->where('si.id', $signupId)
            ->where('st.kermesse_id', $kermesseId)
            ->whereNotIn('si.status', self::INACTIVE_STATUSES)
            ->where('si.deleted_at', null)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Cancel a signup from an admin action — no user-ownership restriction.
     * Returns true when exactly one active row flipped to CANCELLED.
     */
    public function markCancelledByAdmin(int $signupId, int $adminUserId): bool
    {
        $this->builder()
            ->where('id', $signupId)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->where('deleted_at', null)
            ->update([
                'status'     => self::STATUS_CANCELLED,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->db->affectedRows() === 1;
    }

    /**
     * Write the admin-editable contact fields to the signups row (Story 5.10 AC2).
     *
     * Only updates first_name/last_name/email/phone on signups — NEVER touches users.
     * Returns true when exactly one row was updated.
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

        // 0 affected rows means all values were already identical — not an error, since the
        // signup's existence was pre-verified by the caller. Both 0 and 1 are success.
        return in_array($this->db->affectedRows(), [0, 1], true);
    }

    /**
     * Return an ACTIVE signup that belongs to $userId AND to $kermesseId, or null.
     *
     * Ownership + scope guard for Story 4.3 cancellation: the signup is bound to the
     * kermesse through slot→stand, so a volunteer cannot target a signup id from
     * another kermesse, and the user_id match enforces that one can only cancel one's
     * own inscription. A miss (wrong owner, wrong kermesse, already inactive,
     * soft-deleted) returns null so the service can answer neutrally.
     *
     * @return array{id: int, user_id: int, slot_id: int}|null
     */
    public function findActiveOwnedInKermesse(int $signupId, int $userId, int $kermesseId): ?array
    {
        $row = $this->db->table($this->table . ' si')
            ->select('si.id, si.user_id, si.slot_id')
            ->join('slots sl', 'sl.id = si.slot_id')
            ->join('stands st', 'st.id = sl.stand_id')
            ->where('si.id', $signupId)
            ->where('si.user_id', $userId)
            ->where('st.kermesse_id', $kermesseId)
            ->whereNotIn('si.status', self::INACTIVE_STATUSES)
            ->where('si.deleted_at', null)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Stamp the modification-tracking columns — Story 5.1.
     *
     * Called by SignupService::stampAdminModification() for every admin correction
     * (Stories 5.3, 5.10, 5.11, 5.12). Returns true only when exactly one row was
     * updated. The signup must exist and not be soft-deleted; a miss (wrong id,
     * already deleted) returns false so the caller can detect the no-op.
     *
     * $modifiedByUserId references users.id (FK RESTRICT): revoking an admin role
     * only touches kermesse_user_roles — the users row persists, so traceability
     * is preserved without any special handling.
     *
     * NOTE: affectedRows() === 1 relies on at least one column value actually changing.
     * last_modified_at is always set to the current second, so the only theoretical
     * false-negative is calling this twice within the same second with the same admin
     * on the same signup. This edge case is not reachable through normal UI actions.
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
     * Transition an ACTIVE signup to CANCELLED, scoped to its owner. Returns true only
     * when exactly one active row flipped.
     *
     * The status guard makes this safe under a double submit / already-cancelled row
     * (no row matches → false, so the place is never "freed twice"), and the user_id
     * guard is defence in depth on top of the service's ownership read. Setting
     * CANCELLED frees the slot instantly: every active-signup count excludes
     * INACTIVE_STATUSES, so public availability recovers the place with no extra write.
     */
    public function markCancelled(int $signupId, int $userId): bool
    {
        $this->builder()
            ->where('id', $signupId)
            ->where('user_id', $userId)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->where('deleted_at', null)
            ->update([
                'status'     => self::STATUS_CANCELLED,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->db->affectedRows() === 1;
    }
}
