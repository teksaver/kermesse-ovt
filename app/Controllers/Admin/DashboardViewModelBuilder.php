<?php

namespace App\Controllers\Admin;

use App\Models\SlotModel;
use App\Models\StandModel;
use App\Services\StandDeletionService;

/**
 * Shared dashboard view model builder used by DashboardController and StandController.
 *
 * Centralises stand + slot enrichment so both controllers produce an identical
 * view model and cannot diverge.
 */
class DashboardViewModelBuilder
{
    /**
     * Build the full dashboard view model.
     *
     * @param array<string, mixed> $kermesse  Kermesse row from DB
     * @param array<string, mixed> $overrides  Per-render overrides (errors, flash, etc.)
     * @return array<string, mixed>
     */
    public function build(array $kermesse, array $overrides = []): array
    {
        $statusMap = [
            'preparation' => ['label' => 'Inscriptions en préparation', 'class' => 'status-badge--preparation'],
            'open'        => ['label' => 'Inscriptions ouvertes',        'class' => 'status-badge--open'],
            'closed'      => ['label' => 'Inscriptions fermées',         'class' => 'status-badge--closed'],
        ];

        $status     = $kermesse['status'] ?? 'preparation';
        $statusInfo = $statusMap[$status] ?? $statusMap['preparation'];

        $standModel      = model(StandModel::class);
        $stands          = $standModel->getActiveForKermesse((int) $kermesse['id']);
        $deletionService = new StandDeletionService();

        // Enrich stands with signup count and deletion mode
        foreach ($stands as &$stand) {
            $activeSignupCount               = $deletionService->countActiveSignups((int) $stand['id']);
            $stand['activeSignupCount']      = $activeSignupCount;
            $stand['deleteConfirmationMode'] = $deletionService->confirmationModeForCount($activeSignupCount);
        }
        unset($stand);

        // Attach active slots to each stand
        $this->attachSlots($stands, $deletionService);

        $defaults = [
            'kermesse'         => $kermesse,
            'statusLabel'      => $statusInfo['label'],
            'statusClass'      => $statusInfo['class'],
            'stands'           => $stands,
            'hasStands'        => count($stands) > 0,
            'isOpen'           => $status === 'open',
            'disabledReason'   => 'Ajoutez au moins un stand avec un créneau avant d\'ouvrir les inscriptions.',
            // Stand form state
            'standErrors'      => [],
            'standInputName'   => '',
            'standEditId'      => null,
            // Slot form state
            'slotErrors'       => [],
            'slotInputs'       => ['start_time' => '', 'end_time' => '', 'capacity' => ''],
            'slotAddStandId'   => null,
            'slotEditSlotId'   => null,
            // Flash / delete
            'flashSuccess'     => null,
            'deleteError'      => null,
            'deleteStandId'    => null,
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Enrich each stand with its active slots and calculated remaining spots.
     *
     * @param array<int, array<string, mixed>> $stands  (passed by reference)
     */
    private function attachSlots(array &$stands, StandDeletionService $deletionService): void
    {
        if (empty($stands)) {
            return;
        }

        $standIds  = array_column($stands, 'id');
        $slotModel = model(SlotModel::class);
        $allSlots  = $slotModel->getActiveForStandIds(array_map('intval', $standIds));

        // Group by stand_id
        $slotsByStand = [];
        foreach ($allSlots as $slot) {
            $slotsByStand[(int) $slot['stand_id']][] = $slot;
        }

        foreach ($stands as &$stand) {
            $standSlots = $slotsByStand[(int) $stand['id']] ?? [];

            foreach ($standSlots as &$s) {
                // Remaining = capacity minus active signups (0 while signups table is absent),
                // using the same active definition as stand deletion. Never below 0.
                $activeSignups       = $deletionService->countActiveSignupsForSlot((int) $s['id']);
                $s['remainingSpots'] = max(0, (int) $s['capacity'] - $activeSignups);
                $s['displayTime']    = $this->formatSlotTime($s['starts_at'], $s['ends_at']);
            }
            unset($s);

            $stand['slots']    = $standSlots;
            $stand['hasSlots'] = ! empty($standSlots);
        }
        unset($stand);
    }

    /**
     * Format a slot time range for display: "09:00 - 10:30".
     */
    private function formatSlotTime(string $startsAt, string $endsAt): string
    {
        $start = substr($startsAt, 11, 5); // "HH:MM" from "YYYY-MM-DD HH:MM:SS"
        $end   = substr($endsAt, 11, 5);

        return "{$start} - {$end}";
    }
}
