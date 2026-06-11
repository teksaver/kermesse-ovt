<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\StandModel;

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

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->redirectWithError($id, 'Le nom du stand est obligatoire.', 'add', $name);
        }

        $standModel = model(StandModel::class);

        if ($standModel->hasActiveDuplicate($id, $name)) {
            return $this->redirectWithError($id, 'Un stand actif avec ce nom existe déjà.', 'add', $name);
        }

        $standModel->insert([
            'kermesse_id'   => $id,
            'name'          => $name,
            'display_order' => $standModel->nextDisplayOrder($id),
            'status'        => StandModel::STATUS_ACTIVE,
        ]);

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

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->redirectWithError($id, 'Le nom du stand est obligatoire.', 'edit', $name, $standId);
        }

        if ($standModel->hasActiveDuplicate($id, $name, $standId)) {
            return $this->redirectWithError($id, 'Un stand actif avec ce nom existe déjà.', 'edit', $name, $standId);
        }

        $standModel->update($standId, ['name' => $name]);

        session()->setFlashdata('success', 'Stand renommé en « ' . esc($name) . ' ».');

        return redirect()->to(site_url("kermesse/{$id}") . '#stands');
    }

    /**
     * Re-render the dashboard with an error for the stands section.
     * Preserves entered name and marks which form (add or edit) is in error.
     */
    private function redirectWithError(
        int $kermesseId,
        string $message,
        string $formContext,
        string $enteredName,
        ?int $editingStandId = null,
    ): mixed {
        $kermesse = model(KermesseModel::class)->find($kermesseId);
        $stands   = model(StandModel::class)->getActiveForKermesse($kermesseId);

        return $this->response->setStatusCode(200)->setBody(view('kermesse/dashboard', [
            'title'           => esc($kermesse['name']),
            'kermesse'        => $kermesse,
            'stands'          => $stands,
            'stand_error'     => $message,
            'stand_form'      => $formContext,
            'stand_name'      => $enteredName,
            'editing_stand_id' => $editingStandId,
        ]));
    }
}
