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
