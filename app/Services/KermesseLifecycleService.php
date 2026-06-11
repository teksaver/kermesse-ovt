<?php

namespace App\Services;

use App\Models\SlotModel;
use App\Models\StandModel;

/**
 * Owns the kermesse publication lifecycle: preparation -> open -> closed.
 *
 * Business rule (single source of truth): a kermesse can only be opened when it
 * has at least one ACTIVE stand carrying at least one ACTIVE slot. Deactivated
 * stands and deactivated slots never count toward publishability.
 *
 * Controllers must call this service for every transition; they must not mutate
 * `kermesses.status` directly.
 */
class KermesseLifecycleService
{
    public const RESULT_SUCCESS            = 'success';
    public const RESULT_NOT_PUBLISHABLE    = 'not_publishable';
    public const RESULT_INVALID_TRANSITION = 'invalid_transition';
    public const RESULT_FAILED             = 'failed';

    /** Exact French copy shown when an open is blocked (matches the dashboard disabled reason). */
    public const REASON_NOT_PUBLISHABLE = 'Ajoutez au moins un stand avec un créneau avant d\'ouvrir les inscriptions.';

    /**
     * Whether the kermesse currently satisfies the publishability rule.
     */
    public function canOpen(int $kermesseId): bool
    {
        $stands = model(StandModel::class)->getActiveForKermesse($kermesseId);
        if (empty($stands)) {
            return false;
        }

        $standIds = array_map(static fn (array $stand): int => (int) $stand['id'], $stands);
        $slots    = model(SlotModel::class)->getActiveForStandIds($standIds);

        return ! empty($slots);
    }

    /**
     * Transition the kermesse to `open` if publishable.
     *
     * @return self::RESULT_* result code
     */
    public function open(int $kermesseId, int $ownerId): string
    {
        if (! $this->canOpen($kermesseId)) {
            return self::RESULT_NOT_PUBLISHABLE;
        }

        if (! $this->openIfPublishable($kermesseId, $ownerId)) {
            return self::RESULT_NOT_PUBLISHABLE;
        }

        return self::RESULT_SUCCESS;
    }

    /**
     * Transition the kermesse to `closed`. Future volunteer signups become
     * unavailable for the later epics; here it only persists the closed state.
     *
     * @return self::RESULT_* result code
     */
    public function close(int $kermesseId, int $ownerId): string
    {
        if ($this->statusForOwner($kermesseId, $ownerId) !== 'open') {
            return self::RESULT_INVALID_TRANSITION;
        }

        if (! $this->setStatus($kermesseId, $ownerId, 'closed', 'open')) {
            return self::RESULT_FAILED;
        }

        return self::RESULT_SUCCESS;
    }

    private function statusForOwner(int $kermesseId, int $ownerId): ?string
    {
        $row = db_connect()->table('kermesses')
            ->select('status')
            ->where('id', $kermesseId)
            ->where('created_by', $ownerId)
            ->get()
            ->getRowArray();

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * Owner-scoped conditional publish. The EXISTS clause keeps the final status
     * write tied to the current active stand/slot state, so stale UI cannot open
     * a kermesse after its last active slot was deactivated.
     */
    private function openIfPublishable(int $kermesseId, int $ownerId): bool
    {
        $db         = db_connect();
        $kermesses = $db->prefixTable('kermesses');
        $stands    = $db->prefixTable('stands');
        $slots     = $db->prefixTable('slots');

        $db->query(
            "UPDATE {$kermesses}
             SET status = ?, updated_at = ?
             WHERE id = ?
               AND created_by = ?
               AND EXISTS (
                   SELECT 1
                   FROM {$stands}
                   INNER JOIN {$slots}
                       ON {$slots}.stand_id = {$stands}.id
                      AND {$slots}.status = ?
                   WHERE {$stands}.kermesse_id = {$kermesses}.id
                     AND {$stands}.status = ?
                   LIMIT 1
               )",
            ['open', date('Y-m-d H:i:s'), $kermesseId, $ownerId, 'active', 'active']
        );

        return $db->affectedRows() === 1;
    }

    /**
     * Owner-scoped status write. Constraining by owner_id is defence in depth on
     * top of the controller's authorization check, so a status change can never
     * leak across owners through the id alone.
     */
    private function setStatus(int $kermesseId, int $ownerId, string $status, ?string $expectedStatus = null): bool
    {
        $db      = db_connect();
        $builder = $db->table('kermesses')
            ->where('id', $kermesseId)
            ->where('created_by', $ownerId);

        if ($expectedStatus !== null) {
            $builder->where('status', $expectedStatus);
        }

        $builder->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $db->affectedRows() === 1;
    }
}
