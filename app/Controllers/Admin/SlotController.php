<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;
use DateTimeImmutable;
use DateTimeZone;

class SlotController extends BaseController
{
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

        ['errors' => $errors, 'startsAt' => $startsAt, 'endsAt' => $endsAt, 'capacity' => $capacity]
            = $this->validateSlotInputs($this->request->getPost(), $kermesse);

        $inputs = [
            'start_time' => $this->safeString($this->request->getPost('start_time')),
            'end_time'   => $this->safeString($this->request->getPost('end_time')),
            'capacity'   => $this->safeString($this->request->getPost('capacity')),
        ];

        if (! empty($errors)) {
            return $this->renderWithSlotErrors($kermesse, $errors, $inputs, null, $slotId);
        }

        model(SlotModel::class)->update($slotId, [
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

        // Capacity validation
        if (! is_string($rawCapacity) || ! preg_match('/^\d+$/', $rawCapacity) || (int) $rawCapacity < 1) {
            $errors['capacity'] = 'La capacité doit être un entier strictement positif.';
        } else {
            $capacity = (int) $rawCapacity;
        }

        // Time validation
        $eventDate = (is_string($kermesse['event_date'] ?? null) && $kermesse['event_date'] !== '')
            ? $kermesse['event_date']
            : date('Y-m-d');
        $timezone = is_string($kermesse['timezone'] ?? null) ? $kermesse['timezone'] : 'Europe/Paris';

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
     * Parse a "HH:MM" or "H:MM" time string combined with an event date.
     * Returns null on invalid input.
     */
    private function parseTime(mixed $timeStr, string $eventDate, string $timezone): ?DateTimeImmutable
    {
        if (! is_string($timeStr) || $timeStr === '') {
            return null;
        }

        $tz = new DateTimeZone($timezone);

        // Try H:i (handles 9:00 and 09:00)
        $dt = DateTimeImmutable::createFromFormat('Y-m-d G:i', "{$eventDate} {$timeStr}", $tz);
        if ($dt !== false) {
            return $dt;
        }

        // Try with seconds (HH:MM:SS from some browsers)
        $dt = DateTimeImmutable::createFromFormat('Y-m-d G:i:s', "{$eventDate} {$timeStr}", $tz);

        return $dt instanceof DateTimeImmutable ? $dt : null;
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
