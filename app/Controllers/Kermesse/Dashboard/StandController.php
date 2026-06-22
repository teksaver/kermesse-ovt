<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\StandModel;
use App\Services\StandDeletionService;
use App\Services\StandDuplicationService;

/**
 * Gestion des stands d'une kermesse — Owner et Admin uniquement.
 * Le RoleFilter[owner,admin] est appliqué sur les routes.
 */
class StandController extends BaseController
{
    /** Max stand name length — matches the `stands.name` VARCHAR(255) column. */
    private const NAME_MAX_LENGTH = 255;

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

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return $this->redirectWithError('Le nom du stand ne doit pas dépasser ' . self::NAME_MAX_LENGTH . ' caractères.', 'add', $name);
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

        // The view escapes the flashdata (esc($success)); escaping here too would double-encode the name.
        session()->setFlashdata('success', 'Stand « ' . $name . ' » ajouté avec succès.');

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

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return $this->redirectWithError('Le nom du stand ne doit pas dépasser ' . self::NAME_MAX_LENGTH . ' caractères.', 'edit', $name, $standId);
        }

        if ($standModel->hasActiveDuplicate($id, $name, $standId)) {
            return $this->redirectWithError('Un stand actif avec ce nom existe déjà.', 'edit', $name, $standId);
        }

        if (!$standModel->update($standId, ['name' => $name])) {
            return $this->redirectWithError('Erreur système lors de la modification.', 'edit', $name, $standId);
        }

        session()->setFlashdata('success', 'Stand renommé en « ' . $name . ' ».');

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

        session()->setFlashdata('success', 'Stand « ' . $stand['name'] . ' » supprimé avec succès.');

        return redirect()->to(site_url("kermesse/{$id}") . '#stands');
    }

    /**
     * POST /kermesse/{kermesse_id}/stands/{stand_id}/duplicate
     *
     * Duplicates a stand and all its active slots under a name chosen by the user.
     * The new stand starts with zero signups: only slot configuration (times +
     * capacity) is copied — never any signup row.
     */
    public function duplicate(string $kermesseId, string $standId): mixed
    {
        $id      = (int) $kermesseId;
        $standId = (int) $standId;

        $standModel = model(StandModel::class);
        $stand = $standModel->where('kermesse_id', $id)
            ->where('status', StandModel::STATUS_ACTIVE)
            ->find($standId);

        if ($stand === null) {
            return $this->response->setStatusCode(404);
        }

        $p       = $this->request->getPost('name');
        $newName = is_string($p) ? trim($p) : '';

        // The error form context 'duplicate' reopens the source stand's duplication
        // modal with the entered value preserved (see dashboard.php).
        if ($newName === '') {
            return $this->redirectWithError('Le nom du nouveau stand est obligatoire.', 'duplicate', $newName, $standId);
        }

        if (mb_strlen($newName) > self::NAME_MAX_LENGTH) {
            return $this->redirectWithError('Le nom du nouveau stand ne doit pas dépasser ' . self::NAME_MAX_LENGTH . ' caractères.', 'duplicate', $newName, $standId);
        }

        // The transactional copy (stand + slots, never signups) is a service-owned
        // invariant — controllers must not write to the DB directly.
        $result = (new StandDuplicationService())->duplicate($id, $standId, $newName);

        if ($result === StandDuplicationService::RESULT_DUPLICATE_NAME) {
            return $this->redirectWithError('Un stand actif avec ce nom existe déjà.', 'duplicate', $newName, $standId);
        }

        if ($result !== StandDuplicationService::RESULT_SUCCESS) {
            return $this->redirectWithError('Erreur système lors de la duplication du stand.', 'duplicate', $newName, $standId);
        }

        session()->setFlashdata('success', 'Stand « ' . $newName . ' » dupliqué avec succès.');

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
            ->with('editing_stand_id', $editingStandId !== null ? (string) $editingStandId : '');
    }
}
