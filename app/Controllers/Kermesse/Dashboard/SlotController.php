<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use CodeIgniter\I18n\Time;

/**
 * Gestion des créneaux d'un stand — Owner et Admin uniquement.
 * Le RoleFilter[owner,admin] est appliqué sur les routes.
 */
class SlotController extends BaseController
{
    /** POST /kermesse/{kermesse_id}/stands/{stand_id}/slots */
    public function store(string $kermesseId, string $standId): mixed
    {
        $id      = (int) $kermesseId;
        $standId = (int) $standId;

        $kermesse = model(KermesseModel::class)->find($id);
        if ($kermesse === null) {
            return $this->response->setStatusCode(404);
        }

        $stand = model(StandModel::class)
            ->where('kermesse_id', $id)
            ->where('status', StandModel::STATUS_ACTIVE)
            ->find($standId);
            
        if ($stand === null) {
            return $this->response->setStatusCode(404);
        }

        $startsAt = is_string($this->request->getPost('starts_at')) ? trim($this->request->getPost('starts_at')) : '';
        $endsAt   = is_string($this->request->getPost('ends_at'))   ? trim($this->request->getPost('ends_at'))   : '';
        $capacityPost = $this->request->getPost('capacity');
        $capacity = is_numeric($capacityPost) ? (int) $capacityPost : 0;

        $eventDate = !empty($kermesse['event_date']) ? $kermesse['event_date'] : date('Y-m-d');
        
        $fullStart = ($startsAt !== '') ? $eventDate . ' ' . $startsAt : '';
        $fullEnd   = ($endsAt !== '')   ? $eventDate . ' ' . $endsAt   : '';

        $timezone = $kermesse['timezone'] ?? date_default_timezone_get();
        $errors = $this->validateSlot($fullStart, $fullEnd, $capacity, $timezone);
        
        if (!empty($errors)) {
            // Restore original input keys to avoid returning full date to UI
            $errors_modified = $errors;
            if (isset($errors['starts_at']) && $startsAt === '') $errors_modified['starts_at'] = 'L\'heure de début est obligatoire.';
            if (isset($errors['ends_at']) && $endsAt === '') $errors_modified['ends_at'] = 'L\'heure de fin est obligatoire.';
            
            return $this->redirectWithErrors($errors_modified, 'add', $id, $standId);
        }

        // Format to standard SQL DATETIME
        $startFormatted = Time::parse($fullStart, $timezone)->format('Y-m-d H:i:s');
        $endFormatted   = Time::parse($fullEnd, $timezone)->format('Y-m-d H:i:s');

        $inserted = model(SlotModel::class)->insert([
            'stand_id'  => $standId,
            'starts_at' => $startFormatted,
            'ends_at'   => $endFormatted,
            'capacity'  => $capacity,
            'status'    => SlotModel::STATUS_ACTIVE,
        ]);

        if (!$inserted) {
            return $this->redirectWithErrors(['general' => 'Erreur de sauvegarde.'], 'add', $id, $standId);
        }

        session()->setFlashdata('success', 'Créneau ajouté avec succès.');

        return redirect()->to(site_url("kermesse/{$id}") . '#slots-stand-' . $standId);
    }

    /** POST /kermesse/{kermesse_id}/slots/{slot_id} */
    public function update(string $kermesseId, string $slotId): mixed
    {
        $id     = (int) $kermesseId;
        $slotId = (int) $slotId;

        $kermesse = model(KermesseModel::class)->find($id);
        if ($kermesse === null) {
            return $this->response->setStatusCode(404);
        }

        $slot = model(SlotModel::class)->find($slotId);
        if ($slot === null) {
            return $this->response->setStatusCode(404);
        }

        $stand = model(StandModel::class)
            ->where('kermesse_id', $id)
            ->where('status', StandModel::STATUS_ACTIVE)
            ->find((int) $slot['stand_id']);
            
        if ($stand === null) {
            return $this->response->setStatusCode(404);
        }

        $startsAt = is_string($this->request->getPost('starts_at')) ? trim($this->request->getPost('starts_at')) : '';
        $endsAt   = is_string($this->request->getPost('ends_at'))   ? trim($this->request->getPost('ends_at'))   : '';
        $capacityPost = $this->request->getPost('capacity');
        $capacity = is_numeric($capacityPost) ? (int) $capacityPost : 0;

        $eventDate = !empty($kermesse['event_date']) ? $kermesse['event_date'] : date('Y-m-d');
        
        $fullStart = ($startsAt !== '') ? $eventDate . ' ' . $startsAt : '';
        $fullEnd   = ($endsAt !== '')   ? $eventDate . ' ' . $endsAt   : '';

        $timezone = $kermesse['timezone'] ?? date_default_timezone_get();
        $errors = $this->validateSlot($fullStart, $fullEnd, $capacity, $timezone);
        
        if (!empty($errors)) {
            // Restore original input keys to avoid returning full date to UI
            $errors_modified = $errors;
            if (isset($errors['starts_at']) && $startsAt === '') $errors_modified['starts_at'] = 'L\'heure de début est obligatoire.';
            if (isset($errors['ends_at']) && $endsAt === '') $errors_modified['ends_at'] = 'L\'heure de fin est obligatoire.';
            
            return $this->redirectWithErrors($errors_modified, 'edit', $id, (int) $slot['stand_id'], $slotId);
        }

        $startFormatted = Time::parse($fullStart, $timezone)->format('Y-m-d H:i:s');
        $endFormatted   = Time::parse($fullEnd, $timezone)->format('Y-m-d H:i:s');

        $updated = model(SlotModel::class)->update($slotId, [
            'starts_at' => $startFormatted,
            'ends_at'   => $endFormatted,
            'capacity'  => $capacity,
        ]);

        if (!$updated) {
            return $this->redirectWithErrors(['general' => 'Erreur de sauvegarde.'], 'edit', $id, (int) $slot['stand_id'], $slotId);
        }

        session()->setFlashdata('success', 'Créneau modifié avec succès.');

        return redirect()->to(site_url("kermesse/{$id}") . '#slots-stand-' . $slot['stand_id']);
    }

    /**
     * Validates slot times and capacity.
     * Returns an array of field-specific error messages.
     */
    private function validateSlot(string $startsAt, string $endsAt, int $capacity, string $timezone): array
    {
        $errors = [];

        if ($capacity < 1) {
            $errors['capacity'] = 'La capacité doit être d\'au moins 1.';
        }

        if ($startsAt === '') {
            $errors['starts_at'] = 'L\'heure de début est obligatoire.';
        }
        if ($endsAt === '') {
            $errors['ends_at'] = 'L\'heure de fin est obligatoire.';
        }

        if ($startsAt !== '' && $endsAt !== '') {
            try {
                $start = Time::parse($startsAt, $timezone);
                $end   = Time::parse($endsAt, $timezone);
                if ($start->getTimestamp() >= $end->getTimestamp()) {
                    $errors['starts_at'] = 'L\'heure de début doit être antérieure à l\'heure de fin.';
                }
            } catch (\Exception $e) {
                $errors['general'] = 'Format de date invalide.';
            }
        }

        return $errors;
    }

    /**
     * Redirects back with input and flashdata for the dashboard slots section.
     */
    private function redirectWithErrors(
        array $errors,
        string $formContext,
        int $kermesseId,
        int $standId,
        ?int $slotId = null,
    ): mixed {
        return redirect()->to(site_url("kermesse/{$kermesseId}") . '#slots-stand-' . $standId)
            ->withInput()
            ->with('slot_errors', $errors)
            ->with('slot_form', $formContext)
            ->with('slot_form_stand_id', $standId)
            ->with('slot_form_slot_id', $slotId);
    }
}
