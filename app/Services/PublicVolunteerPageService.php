<?php

namespace App\Services;

use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;

/**
 * Builds the privacy-safe view model for the public volunteer page (GET /k/{slug}).
 *
 * PRIVACY BOUNDARY: this is the only place the public page gets its data, and it
 * deliberately whitelists fields. It must NEVER expose volunteer names, emails,
 * phone numbers, admin data, owner data, or management links. Only event, stand,
 * slot and availability data is surfaced. The public page loads stands and slots
 * ONLY when the kermesse is open; buildSlotSummary additionally serves closed
 * kermesses so the signup POST can answer with the AC4 "not open" message (story
 * 3.4 review trade-off). Preparation state reveals nothing about the planning.
 */
class PublicVolunteerPageService
{
    private const STATUS_MAP = [
        KermesseModel::STATUS_PREPARATION => ['label' => 'Inscriptions à venir', 'class' => 'status-badge--preparation'],
        KermesseModel::STATUS_OPEN        => ['label' => 'Inscriptions ouvertes', 'class' => 'status-badge--open'],
        KermesseModel::STATUS_CLOSED      => ['label' => 'Inscriptions clôturées', 'class' => 'status-badge--closed'],
    ];

    /**
     * Build the public view model from a public slug, or null if no kermesse matches.
     *
     * @return array<string, mixed>|null
     */
    public function buildForSlug(string $publicSlug, ?int $userId = null): ?array
    {
        $kermesse = model(KermesseModel::class)
            ->where('public_slug', $publicSlug)
            ->first();

        if ($kermesse === null) {
            return null;
        }

        $status     = $kermesse['status'] ?? KermesseModel::STATUS_PREPARATION;
        $statusInfo = self::STATUS_MAP[$status] ?? self::STATUS_MAP[KermesseModel::STATUS_PREPARATION];

        // Whitelist public-safe kermesse fields only. Never spread the raw row,
        // which carries owner_id and other non-public columns.
        $publicKermesse = [
            'name'              => (string) ($kermesse['name'] ?? ''),
            'event_date'        => $kermesse['event_date'] ?? null,
            'location'          => $kermesse['location'] ?? null,
            'short_description' => $kermesse['short_description'] ?? null,
        ];

        $stands = [];
        if ($status === KermesseModel::STATUS_OPEN) {
            $stands = $this->buildStands((int) $kermesse['id'], $publicSlug, $userId);
        }

        return [
            'kermesse'    => $publicKermesse,
            'status'      => $status,
            'statusLabel' => $statusInfo['label'],
            'statusClass' => $statusInfo['class'],
            'signupsOpen' => $status === KermesseModel::STATUS_OPEN,
            'stands'      => $stands,
            'hasStands'   => count($stands) > 0,
            'hasSlots'    => $this->hasSlots($stands),
        ];
    }

    /**
     * Build a privacy-safe summary for a single slot, used by the signup form.
     *
     * Returns null if the kermesse is in preparation, or the slot/stand is inactive
     * or does not belong to this kermesse — callers render a neutral 404. Open AND
     * closed kermesses get a summary: the signup POST needs the closed state to show
     * the French "not open" message instead of a silent 404 (story 3.4 AC4).
     *
     * PRIVACY: only kermesse name, stand name, slot timing, and availability are
     * exposed. No volunteer data, no owner/admin fields, no management links.
     *
     * @return array<string, mixed>|null
     */
    public function buildSlotSummary(string $publicSlug, int $slotId): ?array
    {
        $slot = model(SlotModel::class)->find($slotId);
        if ($slot === null || $slot['status'] !== SlotModel::STATUS_ACTIVE || $slot['ends_at'] < date('Y-m-d H:i:s')) {
            return null;
        }

        $stand = model(StandModel::class)->find($slot['stand_id']);
        if ($stand === null || $stand['status'] !== StandModel::STATUS_ACTIVE) {
            return null;
        }

        $kermesse = model(KermesseModel::class)->find($stand['kermesse_id']);
        if ($kermesse === null
            || ! in_array($kermesse['status'], [KermesseModel::STATUS_OPEN, KermesseModel::STATUS_CLOSED], true)
            || $kermesse['public_slug'] !== $publicSlug) {
            return null;
        }

        $activeSignups  = model(SignupModel::class)->countActiveBySlotIds([$slotId])[$slotId] ?? 0;
        $capacity       = (int) $slot['capacity'];
        $remainingSpots = max(0, $capacity - $activeSignups);

        return [
            'kermesseName'   => (string) $kermesse['name'],
            'standName'      => (string) $stand['name'],
            'displayTime'    => $this->formatSlotTime((string) $slot['starts_at'], (string) $slot['ends_at']),
            'capacity'       => $capacity,
            'remainingSpots' => $remainingSpots,
            'isFull'         => $remainingSpots === 0,
            'publicSlug'     => $publicSlug,
            'slotId'         => $slotId,
            'kermesseId'     => (int) $kermesse['id'],
            'kermesseStatus' => $kermesse['status'],
        ];
    }

    /**
     * Load active stands with their active slots, keeping only display-safe fields.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildStands(int $kermesseId, string $publicSlug, ?int $userId = null): array
    {
        $stands = model(StandModel::class)->getActiveForKermesse($kermesseId);
        if (empty($stands)) {
            return [];
        }

        $standIds = array_map('intval', array_column($stands, 'id'));
        $now = date('Y-m-d H:i:s');
        $allSlots = array_filter(
            model(SlotModel::class)->getActiveForStandIds($standIds),
            fn($slot) => $slot['ends_at'] >= $now
        );

        $signupCounts = model(SignupModel::class)->countActiveBySlotIds(array_column($allSlots, 'id'));

        $userSignups = [];
        if ($userId !== null && !empty($allSlots)) {
            $signups = model(SignupModel::class)
                ->where('user_id', $userId)
                ->where('status', \App\Models\SignupModel::STATUS_ACTIVE)
                ->whereIn('slot_id', array_column($allSlots, 'id'))
                ->findAll();
            $userSignups = array_flip(array_column($signups, 'slot_id'));
        }

        $slotsByStand = [];
        foreach ($allSlots as $slot) {
            $slotsByStand[(int) $slot['stand_id']][] = $this->buildSlot(
                $slot,
                $signupCounts[(int) $slot['id']] ?? 0,
                $publicSlug,
                isset($userSignups[(int) $slot['id']])
            );
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
    private function buildSlot(array $slot, int $activeSignups, string $publicSlug, bool $isSignedUp = false): array
    {
        $capacity = (int) $slot['capacity'];

        // Remaining uses the same active-signup definition as admin counters so
        // the public availability and admin planning never diverge. Never below 0.
        $remainingSpots = max(0, $capacity - $activeSignups);
        $slotId         = (int) $slot['id'];
        $isFull         = $remainingSpots === 0;

        return [
            'slotId'         => $slotId,
            'signupHref'     => ($isFull || $isSignedUp) ? null : site_url("k/{$publicSlug}/slots/{$slotId}/signup"),
            'displayTime'    => $this->formatSlotTime((string) $slot['starts_at'], (string) $slot['ends_at']),
            'capacity'       => $capacity,
            'remainingSpots' => $remainingSpots,
            'isFull'         => $isFull,
            'isSignedUp'     => $isSignedUp,
        ];
    }

    /**
     * Format a slot time range for display: "09:00 - 10:30".
     */
    private function formatSlotTime(string $startsAt, string $endsAt): string
    {
        try {
            $start = \CodeIgniter\I18n\Time::parse($startsAt)->format('H:i');
            $end   = \CodeIgniter\I18n\Time::parse($endsAt)->format('H:i');
            return "{$start} - {$end}";
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $stands
     */
    private function hasSlots(array $stands): bool
    {
        foreach ($stands as $stand) {
            if (! empty($stand['slots'])) {
                return true;
            }
        }

        return false;
    }
}
