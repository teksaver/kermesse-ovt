<?php

namespace App\Services;

class StandDeletionService
{
    public function countActiveSignups(int $standId): int
    {
        $db = db_connect();

        // Reset stale table list cache before checking (shared in-memory DB in test suites
        // can leave the cache populated before these tables were created).
        $db->resetDataCache();

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

        $slotIds = array_column($slotIds, 'id');

        return (int) $db->table('signups')
            ->whereIn('slot_id', $slotIds)
            ->whereNotIn('status', ['cancelled', 'deactivated'])
            ->countAllResults();
    }

    // Deactivates stand and its active signups atomically.
    public function deactivate(int $standId, int $kermesseId): bool
    {
        $db = db_connect();
        $db->transStart();

        $db->table('stands')
            ->where('id', $standId)
            ->where('kermesse_id', $kermesseId)
            ->where('status', 'active')
            ->set('status', 'deactivated')
            ->update();

        if ($db->tableExists('slots') && $db->tableExists('signups')) {
            $slotIds = $db->table('slots')
                ->select('id')
                ->where('stand_id', $standId)
                ->get()
                ->getResultArray();

            if (! empty($slotIds)) {
                $slotIds = array_column($slotIds, 'id');
                $db->table('signups')
                    ->whereIn('slot_id', $slotIds)
                    ->whereNotIn('status', ['cancelled', 'deactivated'])
                    ->set('status', 'deactivated')
                    ->update();
            }
        }

        $db->transComplete();

        return $db->transStatus();
    }
}
