<?php

namespace App\Services;

use App\Models\SlotModel;
use App\Models\StandModel;

/**
 * Duplicates a stand and its slot configuration under a new name.
 *
 * Business responsibility: clone an active stand's structure (its active slots:
 * times + capacity) into a brand-new stand, while guaranteeing the new stand
 * starts with zero participants — signups are NEVER copied. The whole copy runs
 * in a single transaction so a stand is never left half-duplicated, and the
 * "name already used by an active stand" invariant is re-checked inside the
 * transaction to stay race-safe against the active-name unique index.
 */
class StandDuplicationService
{
    public const RESULT_SUCCESS        = 'success';
    public const RESULT_DUPLICATE_NAME = 'duplicate_name';
    public const RESULT_FAILED         = 'failed';

    /**
     * Duplicate $sourceStandId into a new active stand named $newName.
     *
     * @return self::RESULT_* outcome code
     */
    public function duplicate(int $kermesseId, int $sourceStandId, string $newName): string
    {
        $standModel = model(StandModel::class);
        $slotModel  = model(SlotModel::class);

        $db = db_connect();
        // Shared in-memory test DBs can leave a stale table-list cache; reset before
        // the transaction so existence checks resolve against the real schema.
        $db->resetDataCache();
        $db->transBegin();

        // Re-check the active-name invariant inside the transaction (defence in depth
        // alongside the uq_stands_active_name unique index).
        if ($standModel->hasActiveDuplicate($kermesseId, $newName)) {
            $db->transRollback();

            return self::RESULT_DUPLICATE_NAME;
        }

        $newStandId = $standModel->insert([
            'kermesse_id'   => $kermesseId,
            'name'          => $newName,
            'display_order' => $standModel->nextDisplayOrder($kermesseId),
            'status'        => StandModel::STATUS_ACTIVE,
        ]);

        if (! $newStandId) {
            $db->transRollback();

            return self::RESULT_FAILED;
        }

        $slots = $slotModel->where('stand_id', $sourceStandId)
            ->where('status', SlotModel::STATUS_ACTIVE)
            ->findAll();

        // Copy slot configuration only (times + capacity). Each cloned slot is a new
        // row with no signups attached: the duplicated stand starts at zero inscrits.
        foreach ($slots as $slot) {
            $slotModel->insert([
                'stand_id'  => $newStandId,
                'starts_at' => $slot['starts_at'],
                'ends_at'   => $slot['ends_at'],
                'capacity'  => $slot['capacity'],
                'status'    => SlotModel::STATUS_ACTIVE,
            ]);
        }

        // Fail-fast: roll back the whole duplication if any insert failed.
        if (! $db->transStatus()) {
            $db->transRollback();

            return self::RESULT_FAILED;
        }

        $db->transCommit();

        return self::RESULT_SUCCESS;
    }
}
