<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;
use App\Services\StandDeletionService;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

class SlotController extends BaseController
{
    /** Upper bound of the `capacity` column (MariaDB INT UNSIGNED). */
    private const CAPACITY_MAX = 4294967295;

    public function create(string $kermesseId, string $standId): mixed
    {
        $id  = (int) $kermesseId;
        $sid = (int) $standId;

        $result = (new AdminAuthorizationService())->checkAccess($id);
        if (! $result->isAuthorized()) {
            return $this->denyAccess($result);
        }

        $kermesse = $this->loadOwnedKermesse($id);
        $stand    = $this->loadActiveStandForKermesse($sid, $id);

        if ($kermesse === null || $stand === null) {
            return $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        ['errors' => $errors, 'startsAt' => $startsAt, 'endsAt' => $endsAt, 'capacity' => $capacity]
            = $this->validateSlotInputs($this->request->getPost(), $kermesse);

        $inputs = [
            'start_time' => $this->safeString($this->request->getPost('start_time')),
            'end_time'   => $this->safeString($this->request->getPost('end_time')),
            'capacity'   => $this->safeString($this->request->getPost('capacity')),
        ];

        if (! empty($errors)) {
            return $this->renderWithSlotErrors($kermesse, $errors, $inputs, $sid, null);
        }

        model(SlotModel::class)->insert([
            'stand_id'   => $sid,
            'starts_at'  => $startsAt,
            'ends_at'    => $endsAt,
            'capacity'   => $capacity,
            'status'     => 'active',
        ]);

        return redirect()
            ->to(site_url("admin/kermesses/{$id}"))
            ->with('flash_success', 'Créneau ajouté.');
    }

    public function update(string $kermesseId, string $standId, string $slotId): mixed
    {
        $id     = (int) $kermesseId;
        $sid    = (int) $standId;
        $slotId = (int) $slotId;

        $result = (new AdminAuthorizationService())->checkAccess($id);
        if (! $result->isAuthorized()) {
            return $this->denyAccess($result);
        }

        $kermesse = $this->loadOwnedKermesse($id);
        $stand    = $this->loadActiveStandForKermesse($sid, $id);

        if ($kermesse === null || $stand === null) {
            return $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        $slot = model(SlotModel::class)
            ->where('id', $slotId)
            ->where('stand_id', $sid)
            ->where('status', 'active')
            ->first();

        if ($slot === null) {
            return $this->denyAccess(new AuthorizationResult(AuthorizationResult::ACCESS_DENIED));
        }

        $inputs = [
            'start_time' => $this->safeString($this->request->getPost('start_time')),
            'end_time'   => $this->safeString($this->request->getPost('end_time')),
            'capacity'   => $this->safeString($this->request->getPost('capacity')),
        ];

        // A slot with active signups must not be edited in this story; capacity/time
        // changes after signup will be handled by the capacity/counter stories.
        if ((new StandDeletionService())->countActiveSignupsForSlot($slotId) > 0) {
            return $this->renderWithSlotErrors(
                $kermesse,
                ['general' => 'Ce créneau a déjà des inscriptions actives.'],
                $inputs,
                null,
                $slotId
            );
        }

        ['errors' => $errors, 'startsAt' => $startsAt, 'endsAt' => $endsAt, 'capacity' => $capacity]
            = $this->validateSlotInputs($this->request->getPost(), $kermesse);

        if (! empty($errors)) {
            return $this->renderWithSlotErrors($kermesse, $errors, $inputs, null, $slotId);
        }

        // Constrain the final write by id + stand + active status so a slot cannot be
        // mutated through its PK alone if it was reparented or deactivated meanwhile.
        model(SlotModel::class)
            ->where('stand_id', $sid)
            ->where('status', 'active')
            ->update($slotId, [
                'starts_at' => $startsAt,
                'ends_at'   => $endsAt,
                'capacity'  => $capacity,
            ]);

        return redirect()
            ->to(site_url("admin/kermesses/{$id}"))
            ->with('flash_success', 'Créneau enregistré.');
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Validate POST inputs for a slot.
     *
     * Returns ['errors' => [...], 'startsAt' => string|null, 'endsAt' => string|null, 'capacity' => int|null]
     *
     * @param mixed                $post
     * @param array<string, mixed> $kermesse
     * @return array<string, mixed>
     */
    private function validateSlotInputs(mixed $post, array $kermesse): array
    {
        $errors   = [];
        $startsAt = null;
        $endsAt   = null;
        $capacity = null;

        $rawCapacity  = $post['capacity']  ?? null;
        $rawStartTime = $post['start_time'] ?? null;
        $rawEndTime   = $post['end_time']   ?? null;

        // Capacity validation: strictly positive integer, bounded to the column type.
        if (! is_string($rawCapacity) || ! preg_match('/^\d+$/', $rawCapacity) || (int) $rawCapacity < 1) {
            $errors['capacity'] = 'La capacité doit être un entier strictement positif.';
        } elseif (! $this->capacityWithinBounds($rawCapacity)) {
            $errors['capacity'] = 'La capacité est trop élevée.';
        } else {
            $capacity = (int) $rawCapacity;
        }

        // Event date must exist; never silently fall back to the server date,
        // which would persist a slot on the wrong day.
        $eventDate = $kermesse['event_date'] ?? null;
        if (! is_string($eventDate) || $eventDate === '') {
            $errors['general'] = 'La date de l\'événement est manquante. Renseignez-la avant d\'ajouter un créneau.';

            return compact('errors', 'startsAt', 'endsAt', 'capacity');
        }

        // Resolve the kermesse timezone defensively: a corrupt stored value must
        // not raise a server exception.
        $timezone = $this->resolveTimezone($kermesse['timezone'] ?? null);

        $startDt = $this->parseTime($rawStartTime, $eventDate, $timezone);
        $endDt   = $this->parseTime($rawEndTime, $eventDate, $timezone);

        if ($startDt === null || $endDt === null || $endDt <= $startDt) {
            $errors['end_time'] = 'L\'heure de fin doit être après l\'heure de début.';
        } else {
            $startsAt = $startDt->format('Y-m-d H:i:s');
            $endsAt   = $endDt->format('Y-m-d H:i:s');
        }

        return compact('errors', 'startsAt', 'endsAt', 'capacity');
    }

    /**
     * Whether a digits-only capacity string fits within the INT UNSIGNED column.
     * Avoids integer overflow on very long inputs by checking digit length first.
     */
    private function capacityWithinBounds(string $digits): bool
    {
        $digits = ltrim($digits, '0');

        return strlen($digits) <= 10 && (int) $digits <= self::CAPACITY_MAX;
    }

    /**
     * Resolve a stored timezone string, falling back to Europe/Paris when it is
     * missing or invalid, so an unparseable value never throws.
     */
    private function resolveTimezone(mixed $timezone): DateTimeZone
    {
        if (is_string($timezone) && $timezone !== '') {
            try {
                return new DateTimeZone($timezone);
            } catch (Exception) {
                // Fall through to the default below.
            }
        }

        return new DateTimeZone('Europe/Paris');
    }

    /**
     * Parse a "HH:MM" (optionally with seconds) time string combined with an event date.
     * Returns null on invalid input.
     *
     * A strict regex rejects out-of-range values (e.g. "25:00", "09:75") that
     * createFromFormat would otherwise silently normalise into a valid datetime.
     */
    private function parseTime(mixed $timeStr, string $eventDate, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (! is_string($timeStr) || ! preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $timeStr)) {
            return null;
        }

        // Try H:i (handles 9:00 and 09:00)
        $dt = DateTimeImmutable::createFromFormat('Y-m-d G:i', "{$eventDate} {$timeStr}", $tz);
        if ($dt === false) {
            // Try with seconds (HH:MM:SS from some browsers)
            $dt = DateTimeImmutable::createFromFormat('Y-m-d G:i:s', "{$eventDate} {$timeStr}", $tz);
        }

        if (! $dt instanceof DateTimeImmutable) {
            return null;
        }

        // Reject any value PHP had to normalise (defence in depth alongside the regex).
        $parseErrors = DateTimeImmutable::getLastErrors();
        if ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)) {
            return null;
        }

        // Some timezone transitions (e.g. Europe/Paris daylight-saving jump)
        // normalise nonexistent local times without warnings. Round-trip the
        // local clock value so "02:30" cannot silently become "03:30".
        $parts          = explode(':', $timeStr);
        $expectedClock  = sprintf(
            '%02d:%02d:%02d',
            (int) $parts[0],
            (int) $parts[1],
            isset($parts[2]) ? (int) $parts[2] : 0
        );
        if ($dt->format('H:i:s') !== $expectedClock) {
            return null;
        }

        return $dt;
    }

    // ------------------------------------------------------------------
    // Rendering helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $kermesse
     * @param array<string, string> $errors
     * @param array<string, string> $inputs
     */
    private function renderWithSlotErrors(
        array $kermesse,
        array $errors,
        array $inputs,
        ?int $addStandId,
        ?int $editSlotId
    ): mixed {
        $builder = new DashboardViewModelBuilder();

        return view('admin/dashboard', $builder->build($kermesse, [
            'slotErrors'     => $errors,
            'slotInputs'     => $inputs,
            'slotAddStandId' => $addStandId,
            'slotEditSlotId' => $editSlotId,
        ]));
    }

    // ------------------------------------------------------------------
    // DB helpers
    // ------------------------------------------------------------------

    private function loadOwnedKermesse(int $id): ?array
    {
        return model(KermesseModel::class)
            ->where('id', $id)
            ->where('owner_id', (int) session('owner_id'))
            ->first();
    }

    private function loadActiveStandForKermesse(int $standId, int $kermesseId): ?array
    {
        return model(StandModel::class)
            ->where('id', $standId)
            ->where('kermesse_id', $kermesseId)
            ->where('status', 'active')
            ->first();
    }

    private function safeString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    // ------------------------------------------------------------------
    // Access denial
    // ------------------------------------------------------------------

    private function denyAccess(AuthorizationResult $result): mixed
    {
        if ($result->status === AuthorizationResult::NO_SESSION) {
            return redirect()->to(site_url('owner/login'));
        }

        if ($result->status === AuthorizationResult::PENDING_VALIDATION) {
            return service('response')
                ->setStatusCode(403)
                ->setBody(view('owner/validation_result', [
                    'status'   => 'validation_required',
                    'loginUrl' => site_url('owner/login'),
                ]));
        }

        return service('response')
            ->setStatusCode(403)
            ->setBody(view('owner/validation_result', [
                'status'   => 'access_denied',
                'loginUrl' => site_url('owner/login'),
            ]));
    }
}
