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

        if (! $db->tableExists('signups')) {
            return 0;
        }

        $builder = $db->table('signups')->where('slot_id', $slotId);
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

        if (! $db->tableExists('slots') || ! $db->tableExists('signups')) {
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
            $builder = $db->table('signups')
                ->whereIn('slot_id', $slotIds)
                ->set('status', 'deactivated')
                ->set('updated_at', date('Y-m-d H:i:s'));

            $this->applyActiveSignupFilter($builder, $db);
            $builder->update();
        }

        $db->transCommit();

        return $db->transStatus() ? self::RESULT_SUCCESS : self::RESULT_FAILED;
    }

    private function countActiveSignupsWithConnection(object $db, int $standId): int
    {
        if (! $db->tableExists('slots') || ! $db->tableExists('signups')) {
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

        $builder = $db->table('signups')
            ->whereIn('slot_id', array_column($slotIds, 'id'));

        $this->applyActiveSignupFilter($builder, $db);

        return (int) $builder->countAllResults();
    }

    private function applyActiveSignupFilter(object $builder, object $db): void
    {
        $builder->whereNotIn('status', ['cancelled', 'deactivated', 'deleted']);

        if ($db->fieldExists('deleted_at', 'signups')) {
            $builder->where('deleted_at', null);
        }
    }
}
