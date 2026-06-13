<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Services\KermesseLifecycleService;
use App\Services\StandDeletionService;

/**
 * Kermesse admin dashboard: stands, slots, lifecycle, participants.
 * Implemented incrementally in Stories 2.1–2.5 and 4.4.
 */
class KermesseAdminController extends BaseController
{
    /** GET /kermesse/{id} */
    public function show(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404'));
        }

        $standModel = model(StandModel::class);
        $stands     = $standModel->getActiveForKermesse($id);
        $standIds   = array_column($stands, 'id');
        $allSlots   = empty($standIds) ? [] : model(SlotModel::class)->getActiveForStandIds($standIds);

        $slotsByStand = [];
        foreach ($allSlots as $slot) {
            $slotsByStand[(int) $slot['stand_id']][] = $slot;
        }

        $requiresStrong = (new StandDeletionService())->strongConfirmationByStand($standIds);

        foreach ($stands as &$stand) {
            $stand['slots']                  = $slotsByStand[(int) $stand['id']] ?? [];
            $stand['requires_strong_confirm'] = $requiresStrong[(int) $stand['id']];
        }
        unset($stand);

        $roleService = new \App\Services\RoleService(model(\App\Models\UserRoleModel::class), model(\App\Models\UserModel::class));
        $userRole    = $roleService->getRoleForUser($id, (int) session()->get('user_id'));

        return view('kermesse/dashboard', [
            'title'              => esc($kermesse['name']),
            'kermesse'           => $kermesse,
            'stands'             => $stands,
            'canManageLifecycle' => in_array($userRole, [\App\Models\UserRoleModel::ROLE_OWNER, \App\Models\UserRoleModel::ROLE_ADMIN], true),
        ]);
    }

    /** POST /kermesse/{id}/open */
    public function open(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404'));
        }

        $result = (new KermesseLifecycleService())->open($id, (int) $kermesse['created_by']);

        if ($result === KermesseLifecycleService::RESULT_SUCCESS) {
            session()->setFlashdata('success', 'La kermesse est ouverte.');
        } else {
            session()->setFlashdata('lifecycle_error', KermesseLifecycleService::REASON_NOT_PUBLISHABLE);
        }

        return redirect()->to(site_url("kermesse/{$id}"));
    }

    /** POST /kermesse/{id}/close */
    public function close(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404'));
        }

        $result = (new KermesseLifecycleService())->close($id, (int) $kermesse['created_by']);

        if ($result === KermesseLifecycleService::RESULT_SUCCESS) {
            session()->setFlashdata('success', 'La kermesse est fermée.');
        } else {
            session()->setFlashdata('lifecycle_error', KermesseLifecycleService::REASON_NOT_PUBLISHABLE);
        }

        return redirect()->to(site_url("kermesse/{$id}"));
    }

    /** POST /kermesse/{id}/edit */
    public function update(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404'));
        }

        $pName = $this->request->getPost('name');
        $name  = is_string($pName) ? trim($pName) : '';

        if ($name === '') {
            return redirect()->back()
                ->withInput()
                ->with('kermesse_edit_error', 'Le nom de la kermesse est obligatoire.')
                ->with('kermesse_form', 'edit');
        }

        $pDate = $this->request->getPost('event_date');
        $eventDate = is_string($pDate) ? trim($pDate) : '';

        $pLoc = $this->request->getPost('location');
        $location = is_string($pLoc) ? trim($pLoc) : '';

        $pDesc = $this->request->getPost('short_description');
        $description = is_string($pDesc) ? trim($pDesc) : '';

        if (! model(KermesseModel::class)->update($id, [
            'name'              => $name,
            'event_date'        => $eventDate,
            'location'          => $location,
            'short_description' => $description,
        ])) {
            return redirect()->back()
                ->withInput()
                ->with('kermesse_edit_error', 'Erreur système lors de la modification.')
                ->with('kermesse_form', 'edit');
        }

        session()->setFlashdata('success', 'Caractéristiques de la kermesse mises à jour avec succès.');
        return redirect()->to(site_url("kermesse/{$id}"));
    }
}
