<?php

namespace App\Services;

class SlotDeletionService
{
    public const RESULT_SUCCESS = 'success';
    public const RESULT_CONFIRMATION_CHANGED = 'confirmation_changed';
    public const RESULT_FAILED = 'failed';

    private StandDeletionService $standDeletionService;

    public function __construct()
    {
        $this->standDeletionService = new StandDeletionService();
    }

    public function countActiveSignups(int $slotId): int
    {
        return $this->standDeletionService->countActiveSignupsForSlot($slotId);
    }

    public function confirmationModeFor(int $slotId): string
    {
        return $this->standDeletionService->confirmationModeForCount($this->countActiveSignups($slotId));
    }

    /**
     * Deactivates the slot and its active signups atomically.
     */
    public function deactivate(int $slotId, string $confirmedMode): string
    {
        $db = db_connect();
        $db->resetDataCache();
        $db->transBegin();

        $activeSignupCount = $this->countActiveSignupsWithConnection($db, $slotId);
        $currentMode = $this->standDeletionService->confirmationModeForCount($activeSignupCount);
        
        if ($currentMode === StandDeletionService::CONFIRM_STRONG && $confirmedMode !== StandDeletionService::CONFIRM_STRONG) {
            $db->transRollback();

            return self::RESULT_CONFIRMATION_CHANGED;
        }

        $db->table('slots')
            ->where('id', $slotId)
            ->where('status', 'active')
            ->set('status', 'deactivated')
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->update();

        if ($db->affectedRows() !== 1) {
            $db->transRollback();

            return self::RESULT_FAILED;
        }

        if ($db->tableExists('signups')) {
            // Soft-delete active signups (Story 5.14: no more status column on signups)
            $now = date('Y-m-d H:i:s');
            $builder = $db->table('signups')
                ->where('slot_id', $slotId)
                ->set('deleted_at', $now)
                ->set('updated_at', $now);

            $this->applyActiveSignupFilter($builder, $db);
            $builder->update();
        }

        $db->transCommit();

        return $db->transStatus() ? self::RESULT_SUCCESS : self::RESULT_FAILED;
    }

    private function countActiveSignupsWithConnection(object $db, int $slotId): int
    {
        if (! $db->tableExists('signups')) {
            return 0;
        }

        $builder = $db->table('signups')->where('slot_id', $slotId);
        $this->applyActiveSignupFilter($builder, $db);

        return (int) $builder->countAllResults();
    }

    private function applyActiveSignupFilter(object $builder, object $db): void
    {
        // Story 5.14: active = no cancellation timestamp and not soft-deleted
        $builder->where('signups.canceled_at', null)
                ->where('signups.rejected_at', null);

        if ($db->fieldExists('deleted_at', 'signups')) {
            $builder->where('signups.deleted_at', null);
        }
    }
}
