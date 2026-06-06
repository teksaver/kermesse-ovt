<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\SlotModel;
use App\Models\StandModel;

/**
 * Builds the privacy-safe view model for the public volunteer page (GET /k/{slug}).
 *
 * PRIVACY BOUNDARY: this is the only place the public page gets its data, and it
 * deliberately whitelists fields. It must NEVER expose volunteer names, emails,
 * phone numbers, admin data, owner data, or management links. Only event, stand,
 * slot and availability data is surfaced. Stands and slots are loaded ONLY when
 * the kermesse is open — preparation and closed states reveal nothing about the
 * planning.
 */
class PublicVolunteerPageService
{
    private const STATUS_MAP = [
        'preparation' => ['label' => 'Inscriptions à venir', 'class' => 'status-badge--preparation'],
        'open'        => ['label' => 'Inscriptions ouvertes', 'class' => 'status-badge--open'],
        'closed'      => ['label' => 'Inscriptions clôturées', 'class' => 'status-badge--closed'],
    ];

    /**
     * Build the public view model from a public slug, or null if no kermesse matches.
     *
     * @return array<string, mixed>|null
     */
    public function buildForSlug(string $publicSlug): ?array
    {
        $kermesse = model(KermesseModel::class)
            ->where('public_slug', $publicSlug)
            ->first();

        if ($kermesse === null) {
            return null;
        }

        $status     = $kermesse['status'] ?? 'preparation';
        $statusInfo = self::STATUS_MAP[$status] ?? self::STATUS_MAP['preparation'];

        // Whitelist public-safe kermesse fields only. Never spread the raw row,
        // which carries owner_id and other non-public columns.
        $publicKermesse = [
            'name'              => (string) ($kermesse['name'] ?? ''),
            'event_date'        => $kermesse['event_date'] ?? null,
            'location'          => $kermesse['location'] ?? null,
            'short_description' => $kermesse['short_description'] ?? null,
        ];

        $stands = [];
        if ($status === 'open') {
            $stands = $this->buildStands((int) $kermesse['id']);
        }

        return [
            'kermesse'    => $publicKermesse,
            'status'      => $status,
            'statusLabel' => $statusInfo['label'],
            'statusClass' => $statusInfo['class'],
            'signupsOpen' => $status === 'open',
            'stands'      => $stands,
            'hasStands'   => count($stands) > 0,
        ];
    }

    /**
     * Load active stands with their active slots, keeping only display-safe fields.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildStands(int $kermesseId): array
    {
        $stands = model(StandModel::class)->getActiveForKermesse($kermesseId);
        if (empty($stands)) {
            return [];
        }

        $standIds = array_map('intval', array_column($stands, 'id'));
        $allSlots = model(SlotModel::class)->getActiveForStandIds($standIds);

        $slotsByStand = [];
        foreach ($allSlots as $slot) {
            $slotsByStand[(int) $slot['stand_id']][] = $this->buildSlot($slot);
        }

        $publicStands = [];
        foreach ($stands as $stand) {
            $publicStands[] = [
                'name'  => (string) ($stand['name'] ?? ''),
                'slots' => $slotsByStand[(int) $stand['id']] ?? [],
            ];
        }

        return $publicStands;
    }

    /**
     * Map a raw slot row to the display-safe slot view model.
     *
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function buildSlot(array $slot): array
    {
        $capacity = (int) $slot['capacity'];

        // Remaining uses the same active-signup definition as admin counters so the
        // public availability and admin planning never diverge. Never below 0.
        $activeSignups  = (new StandDeletionService())->countActiveSignupsForSlot((int) $slot['id']);
        $remainingSpots = max(0, $capacity - $activeSignups);

        return [
            'displayTime'    => $this->formatSlotTime((string) $slot['starts_at'], (string) $slot['ends_at']),
            'capacity'       => $capacity,
            'remainingSpots' => $remainingSpots,
            'isFull'         => $remainingSpots === 0,
        ];
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
