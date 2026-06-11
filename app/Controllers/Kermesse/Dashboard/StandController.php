<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\StandModel;
use App\Services\StandDeletionService;

/**
 * Gestion des stands d'une kermesse — Owner et Admin uniquement.
 * Le RoleFilter[owner,admin] est appliqué sur les routes.
 */
class StandController extends BaseController
{
    /** POST /kermesse/{kermesse_id}/stands */
    public function store(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404);
        }

        $p    = $this->request->getPost('name');
        $name = is_string($p) ? trim($p) : '';

        if ($name === '') {
            return $this->redirectWithError('Le nom du stand est obligatoire.', 'add', $name);
        }

        $standModel = model(StandModel::class);

        if ($standModel->hasActiveDuplicate($id, $name)) {
            return $this->redirectWithError('Un stand actif avec ce nom existe déjà.', 'add', $name);
        }

        if (!$standModel->insert([
            'kermesse_id'   => $id,
            'name'          => $name,
            'display_order' => $standModel->nextDisplayOrder($id),
            'status'        => StandModel::STATUS_ACTIVE,
        ])) {
            return $this->redirectWithError('Erreur système lors de l\'ajout.', 'add', $name);
        }

        session()->setFlashdata('success', 'Stand « ' . esc($name) . ' » ajouté avec succès.');

        return redirect()->to(site_url("kermesse/{$id}") . '#stands');
    }

    /** POST /kermesse/{kermesse_id}/stands/{stand_id} */
    public function update(string $kermesseId, string $standId): mixed
    {
        $id      = (int) $kermesseId;
        $standId = (int) $standId;

        $standModel = model(StandModel::class);
        $stand      = $standModel->where('kermesse_id', $id)->find($standId);

        if ($stand === null) {
            return $this->response->setStatusCode(404);
        }

        $p    = $this->request->getPost('name');
        $name = is_string($p) ? trim($p) : '';

        if ($name === '') {
            return $this->redirectWithError('Le nom du stand est obligatoire.', 'edit', $name, $standId);
        }

        if ($standModel->hasActiveDuplicate($id, $name, $standId)) {
            return $this->redirectWithError('Un stand actif avec ce nom existe déjà.', 'edit', $name, $standId);
        }

        if (!$standModel->update($standId, ['name' => $name])) {
            return $this->redirectWithError('Erreur système lors de la modification.', 'edit', $name, $standId);
        }

        session()->setFlashdata('success', 'Stand renommé en « ' . esc($name) . ' ».');

        return redirect()->to(site_url("kermesse/{$id}") . '#stands');
    }

    /**
     * POST /kermesse/{kermesse_id}/stands/{stand_id}/delete
     *
     * Soft-deletes (deactivates) the stand and renders its active signups inactive
     * through StandDeletionService. UX-DR12: strong confirmation (exact word SUPPRIMER)
     * is required when active signups exist; the service re-checks inside its transaction
     * so a signup arriving between display and submit is caught (confirmation_changed).
     */
    public function delete(string $kermesseId, string $standId): mixed
    {
        $id      = (int) $kermesseId;
        $standId = (int) $standId;

        // Explicit where(id) + first() so the kermesse-isolation filter is unambiguously
        // part of the lookup (a stand from another kermesse must read as "not found").
        $stand = model(StandModel::class)
            ->where('id', $standId)
            ->where('kermesse_id', $id)
            ->where('status', StandModel::STATUS_ACTIVE)
            ->first();

        if ($stand === null) {
            return $this->response->setStatusCode(404);
        }

        $service = new StandDeletionService();

        // UX-DR12: require the exact word only when strong confirmation is currently needed.
        if ($service->confirmationModeFor($standId) === StandDeletionService::CONFIRM_STRONG) {
            $p       = $this->request->getPost('confirm');
            $confirm = is_string($p) ? trim($p) : '';

            if (mb_strtoupper($confirm) !== 'SUPPRIMER') {
                return redirect()->back()
                    ->with('delete_error_' . $standId, 'Ce stand a des bénévoles inscrits. Saisissez SUPPRIMER pour confirmer la suppression.');
            }

            $confirmedMode = StandDeletionService::CONFIRM_STRONG;
        } else {
            $confirmedMode = StandDeletionService::CONFIRM_SIMPLE;
        }

        $result = $service->deactivate($standId, $id, $confirmedMode);

        if ($result === StandDeletionService::RESULT_CONFIRMATION_CHANGED) {
            return redirect()->back()
                ->with('delete_error_' . $standId, 'Des inscriptions ont été ajoutées entre-temps. Saisissez SUPPRIMER pour confirmer la suppression.');
        }

        if ($result !== StandDeletionService::RESULT_SUCCESS) {
            return redirect()->back()
                ->with('delete_error_' . $standId, 'Ce stand a déjà été modifié ou une erreur système est survenue.');
        }

        session()->setFlashdata('success', 'Stand « ' . esc($stand['name']) . ' » supprimé avec succès.');

        return redirect()->to(site_url("kermesse/{$id}") . '#stands');
    }

    /**
     * Redirects back with input and flashdata for the dashboard stands section.
     */
    private function redirectWithError(
        string $message,
        string $formContext,
        string $enteredName,
        ?int $editingStandId = null,
    ): mixed {
        return redirect()->back()
            ->withInput()
            ->with('stand_error', $message)
            ->with('stand_form', $formContext)
            ->with('stand_name', $enteredName)
            ->with('editing_stand_id', $editingStandId);
    }
}
