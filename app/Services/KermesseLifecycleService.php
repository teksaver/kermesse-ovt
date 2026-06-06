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
    public const RESULT_SUCCESS         = 'success';
    public const RESULT_NOT_PUBLISHABLE = 'not_publishable';

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

        $this->setStatus($kermesseId, $ownerId, 'open');

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
        $this->setStatus($kermesseId, $ownerId, 'closed');

        return self::RESULT_SUCCESS;
    }

    /**
     * Owner-scoped status write. Constraining by owner_id is defence in depth on
     * top of the controller's authorization check, so a status change can never
     * leak across owners through the id alone.
     */
    private function setStatus(int $kermesseId, int $ownerId, string $status): void
    {
        $db = db_connect();
        $db->table('kermesses')
            ->where('id', $kermesseId)
            ->where('owner_id', $ownerId)
            ->update([
                'status'     => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
