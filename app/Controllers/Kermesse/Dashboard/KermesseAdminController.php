<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\KermesseLifecycleService;
use App\Services\RoleService;
use App\Services\StandDeletionService;
use CodeIgniter\I18n\Time;

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

        $userId      = (int) session()->get('user_id');
        $roleService = new RoleService(model(UserRoleModel::class), model(UserModel::class));
        $userRole    = $roleService->getRoleForUser($id, $userId);

        // Story 4.1 — rendu du tableau de bord par rôle (UX-DR16 / NFR4).
        // "Modification"            : Owner/Admin           → édition kermesse, lifecycle, stands/créneaux.
        // "Gestion des participants": Owner/Admin/Gestionnaire.
        // "Mes participations"      : tout rôle.
        $canModify             = in_array($userRole, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN], true);
        $canManageParticipants = in_array($userRole, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN, UserRoleModel::ROLE_GESTIONNAIRE], true);

        // Minimisation des données : ne charger les stands/créneaux que pour les
        // rôles autorisés à la section "Modification".
        $stands = [];
        if ($canModify) {
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
                $stand['slots']                   = $slotsByStand[(int) $stand['id']] ?? [];
                $stand['requires_strong_confirm'] = $requiresStrong[(int) $stand['id']];
            }
            unset($stand);
        }

        // "Mes participations" : inscriptions actives de l'utilisateur courant
        // (tout rôle). ViewModel pré-formaté — la vue ne fait ni requête ni
        // formatage (NFR). Date/heures interprétées dans le fuseau de la kermesse,
        // symétriquement à la création du créneau (SlotController, Time::parse).
        $timezone         = (string) ($kermesse['timezone'] ?? 'Europe/Paris');
        $myParticipations = array_map(
            static function (array $p) use ($timezone): array {
                $start = Time::parse((string) $p['starts_at'], $timezone);
                $end   = Time::parse((string) $p['ends_at'], $timezone);

                return [
                    'stand_name' => $p['stand_name'],
                    'date'       => $start->format('d/m/Y'),
                    'start_time' => $start->format('H:i'),
                    'end_time'   => $end->format('H:i'),
                ];
            },
            model(SignupModel::class)->findActiveForUserAndKermesse($userId, $id),
        );

        return view('kermesse/dashboard', [
            'title'                 => esc($kermesse['name']),
            'kermesse'              => $kermesse,
            'stands'                => $stands,
            'canModify'             => $canModify,
            'canManageParticipants' => $canManageParticipants,
            'myParticipations'      => $myParticipations,
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
