<?php

namespace App\Services;

class StandDeletionService
{
    public const CONFIRM_SIMPLE = 'simple';
    public const CONFIRM_STRONG = 'strong';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_CONFIRMATION_CHANGED = 'confirmation_changed';
    public const RESULT_FAILED = 'failed';

    public function countActiveSignups(int $standId): int
    {
        $db = db_connect();

        // Reset stale table list cache before checking (shared in-memory DB in test suites
        // can leave the cache populated before these tables were created).
        $db->resetDataCache();

        return $this->countActiveSignupsWithConnection($db, $standId);
    }

    // Counts active signups for a single slot, using the same active-signup
    // definition as stand deletion so admin and volunteer counters stay coherent.
    public function countActiveSignupsForSlot(int $slotId): int
    {
        $db = db_connect();
        $db->resetDataCache();

        if (! $db->tableExists('slot_signups')) {
            return 0;
        }

        $builder = $db->table('slot_signups')->where('slot_id', $slotId);
        $this->applyActiveSignupFilter($builder, $db);

        return (int) $builder->countAllResults();
    }

    public function confirmationModeForCount(int $activeSignupCount): string
    {
        return $activeSignupCount > 0 ? self::CONFIRM_STRONG : self::CONFIRM_SIMPLE;
    }

    public function confirmationModeFor(int $standId): string
    {
        return $this->confirmationModeForCount($this->countActiveSignups($standId));
    }

    /**
     * Returns, for each given stand, whether deleting it requires strong confirmation
     * (i.e. it has at least one active signup). Resolved in a constant number of queries
     * regardless of the stand count, so the dashboard avoids an N+1 per stand.
     *
     * @param int[] $standIds
     * @return array<int, bool> stand_id => requires strong confirmation
     */
    public function strongConfirmationByStand(array $standIds): array
    {
        $requiresStrong = array_fill_keys($standIds, false);
        if ($standIds === []) {
            return $requiresStrong;
        }

        $db = db_connect();
        $db->resetDataCache();

        // Pre-Epic-3: signups/slots tables may not exist yet — nothing can require strong confirm.
        if (! $db->tableExists('slots') || ! $db->tableExists('slot_signups')) {
            return $requiresStrong;
        }

        $builder = $db->table('slot_signups')
            ->select('slots.stand_id')
            ->join('slots', 'slots.id = slot_signups.slot_id')
            ->whereIn('slots.stand_id', $standIds)
            ->groupBy('slots.stand_id');

        $this->applyActiveSignupFilter($builder, $db);

        foreach ($builder->get()->getResultArray() as $row) {
            $requiresStrong[(int) $row['stand_id']] = true;
        }

        return $requiresStrong;
    }

    // Deactivates stand and its active signups atomically.
    public function deactivate(int $standId, int $kermesseId, string $confirmedMode): string
    {
        $db = db_connect();
        $db->resetDataCache();
        $db->transBegin();

        $currentMode = $this->confirmationModeForCount($this->countActiveSignupsWithConnection($db, $standId));
        if ($currentMode === self::CONFIRM_STRONG && $confirmedMode !== self::CONFIRM_STRONG) {
            $db->transRollback();

            return self::RESULT_CONFIRMATION_CHANGED;
        }

        $db->table('stands')
            ->where('id', $standId)
            ->where('kermesse_id', $kermesseId)
            ->where('status', 'active')
            ->set('status', 'deactivated')
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->update();

        if ($db->affectedRows() !== 1) {
            $db->transRollback();

            return self::RESULT_FAILED;
        }

        if (! $db->tableExists('slots') || ! $db->tableExists('slot_signups')) {
            $db->transCommit();

            return $db->transStatus() ? self::RESULT_SUCCESS : self::RESULT_FAILED;
        }

        $slotIds = $db->table('slots')
            ->select('id')
            ->where('stand_id', $standId)
            ->get()
            ->getResultArray();

        if (! empty($slotIds)) {
            $slotIds = array_column($slotIds, 'id');
            // Soft-delete active signups (Story 5.14: no more status column on signups)
            $now = date('Y-m-d H:i:s');
            $builder = $db->table('slot_signups')
                ->whereIn('slot_id', $slotIds)
                ->set('deleted_at', $now)
                ->set('updated_at', $now);

            $this->applyActiveSignupFilter($builder, $db);
            $builder->update();

            $db->table('slots')
                ->where('stand_id', $standId)
                ->where('status', 'active')
                ->set('status', 'deactivated')
                ->set('updated_at', $now)
                ->update();
        }

        $db->transCommit();

        return $db->transStatus() ? self::RESULT_SUCCESS : self::RESULT_FAILED;
    }

    private function countActiveSignupsWithConnection(object $db, int $standId): int
    {
        if (! $db->tableExists('slots') || ! $db->tableExists('slot_signups')) {
            return 0;
        }

        $slotIds = $db->table('slots')
            ->select('id')
            ->where('stand_id', $standId)
            ->get()
            ->getResultArray();

        if (empty($slotIds)) {
            return 0;
        }

        $builder = $db->table('slot_signups')
            ->whereIn('slot_id', array_column($slotIds, 'id'));

        $this->applyActiveSignupFilter($builder, $db);

        return (int) $builder->countAllResults();
    }

    private function applyActiveSignupFilter(object $builder, object $db): void
    {
        // Story 5.14: active = no cancellation timestamp and not soft-deleted
        $builder->where('slot_signups.canceled_at', null)
                ->where('slot_signups.rejected_at', null);

        if ($db->fieldExists('deleted_at', 'slot_signups')) {
            $builder->where('slot_signups.deleted_at', null);
        }
    }
}
