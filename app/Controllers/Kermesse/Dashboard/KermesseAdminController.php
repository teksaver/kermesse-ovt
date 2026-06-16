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

        // Story 5.2 — suivi d'accès par kermesse: first_access_at (1er chargement)
        // et last_access_at (chaque chargement). Hooks Story 5.4 et 5.10 à venir.
        $roleService->recordAccess($id, $userId);

        // Story 4.1 — rendu du tableau de bord par rôle (UX-DR16 / NFR4).
        // "Modification"            : Owner/Admin           → édition kermesse, lifecycle, stands/créneaux.
        // "Gestion des participants": Owner/Admin/Gestionnaire.
        // "Mes participations"      : tout rôle.
        $canModify             = in_array($userRole, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN], true);
        $canManageParticipants = in_array($userRole, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN, UserRoleModel::ROLE_GESTIONNAIRE], true);
        // Story 4.5 — inviter Admin/Gestionnaire : réservé à Owner/Admin. Un Gestionnaire gère
        // les participants mais ne peut pas constituer l'équipe d'administration (Dev Notes 4.5).
        $canInvite             = in_array($userRole, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN], true);

        // Charger stands + créneaux dès qu'une section en a besoin : « Modification »
        // (Owner/Admin) ET « Gestion des participants » (Owner/Admin/Gestionnaire).
        // Minimisation des données : un Bénévole ne déclenche aucun de ces chargements.
        $stands = [];
        if ($canModify || $canManageParticipants) {
            $standModel = model(StandModel::class);
            $stands     = $standModel->getActiveForKermesse($id);
            $standIds   = array_column($stands, 'id');
            $allSlots   = empty($standIds) ? [] : model(SlotModel::class)->getActiveForStandIds($standIds);

            $slotsByStand = [];
            foreach ($allSlots as $slot) {
                $slotsByStand[(int) $slot['stand_id']][] = $slot;
            }

            foreach ($stands as &$stand) {
                $stand['slots'] = $slotsByStand[(int) $stand['id']] ?? [];
            }
            unset($stand);

            // Augmentation propre à « Modification » : confirmation forte de suppression.
            if ($canModify) {
                $requiresStrong = (new StandDeletionService())->strongConfirmationByStand($standIds);
                foreach ($stands as &$stand) {
                    $stand['requires_strong_confirm'] = $requiresStrong[(int) $stand['id']];
                }
                unset($stand);
            }
        }

        $timezone = (string) ($kermesse['timezone'] ?? 'Europe/Paris');

        // ViewModel « Gestion des participants » (Story 4.4) : récapitulatif nominatif
        // par stand/créneau avec places occupées/restantes. La PII (nom, prénom,
        // téléphone, email) est confinée à ce ViewModel, lui-même gardé par le rôle
        // (NFR5) ; les places occupées reposent sur la MÊME définition d'inscription
        // active que la disponibilité publique, donc admin et public ne divergent jamais.
        $participantStands = $canManageParticipants
            ? $this->buildParticipantStands($id, $stands, $timezone)
            : [];

        // "Mes participations" : inscriptions actives de l'utilisateur courant
        // (tout rôle). ViewModel pré-formaté — la vue ne fait ni requête ni
        // formatage (NFR). Date/heures interprétées dans le fuseau de la kermesse,
        // symétriquement à la création du créneau (SlotController, Time::parse).
        $myParticipations = array_map(
            static function (array $p) use ($timezone): array {
                $start = Time::parse((string) $p['starts_at'], $timezone);
                $end   = Time::parse((string) $p['ends_at'], $timezone);

                return [
                    'signup_id'  => (int) $p['signup_id'],
                    'stand_name' => $p['stand_name'],
                    'date'       => $start->format('d/m/Y'),
                    'start_time' => $start->format('H:i'),
                    'end_time'   => $end->format('H:i'),
                ];
            },
            model(SignupModel::class)->findActiveForUserAndKermesse($userId, $id),
        );

        $teamMembers = [];
        if ($canInvite) {
            $roleService = new RoleService(model(UserRoleModel::class), model(UserModel::class));
            $teamMembers = $roleService->getTeamMembersGroupedByStatus($id);
        }

        // Story 5.9 — "Quitter cette kermesse" : visible uniquement si non-Owner ET
        // aucune inscription active. On réutilise $myParticipations déjà calculé pour
        // éviter une requête supplémentaire (la définition d'inscription active est identique).
        $canLeave = $userRole !== UserRoleModel::ROLE_OWNER && empty($myParticipations);

        // Story 5.2 — onglets autorisés, ordre canonique (UX-DR16 / NFR4).
        // Onglets non autorisés absents du tableau → absents du DOM.
        $tabs = [];
        if ($canModify)             { $tabs[] = ['id' => 'modification',   'label' => 'Modification']; }
        if ($canManageParticipants) { $tabs[] = ['id' => 'inscrits',       'label' => 'Gestion des inscrits']; }
        if ($canInvite)             { $tabs[] = ['id' => 'equipe',         'label' => 'Équipe']; }
        $tabs[] =                               ['id' => 'participations', 'label' => 'Mes participations'];

        return view('kermesse/dashboard', [
            'title'                 => esc($kermesse['name']),
            'kermesse'              => $kermesse,
            'stands'                => $stands,
            'canModify'             => $canModify,
            'canManageParticipants' => $canManageParticipants,
            'canInvite'             => $canInvite,
            'canLeave'              => $canLeave,
            'isBenevole'            => $userRole === UserRoleModel::ROLE_BENEVOLE,
            'participantStands'     => $participantStands,
            'myParticipations'      => $myParticipations,
            'teamMembers'           => $teamMembers,
            'tabs'                  => $tabs,
            // Décision métier préparée pour la vue : l'annulation d'une participation
            // n'est proposée que lorsque les inscriptions sont ouvertes (Story 4.3, AC2).
            'signupsOpen'           => $kermesse['status'] === KermesseModel::STATUS_OPEN,
        ]);
    }

    /**
     * Assemble the "Gestion des inscrits" view model: each active stand with its
     * slots, and for every slot the occupied/remaining places plus the nominative list
     * of active volunteers (Story 4.4/5.3, UX-DR24).
     *
     * Occupancy is derived from SignupModel::findActiveParticipantsForKermesse(), whose
     * "active" definition is identical to public availability — the recap can therefore
     * never show a fill level different from the public page (AC). Empty slots keep an
     * empty volunteer list so the operator still sees the remaining capacity.
     *
     * Story 5.3: each volunteer entry carries modifier_label (null when unmodified) for
     * the discrete "Modifié par [Prénom] le [date]" badge in the view.
     *
     * @param array<int, array<string, mixed>> $stands Active stands already loaded with their 'slots'.
     * @return list<array{name: string, slots: list<array{slot_id: int, date: string, start_time: string, end_time: string, capacity: int, occupied: int, remaining: int, volunteers: list<array{signup_id: int, first_name: string, last_name: string, phone: string, email: string, admin_notes: string, locked: bool, modifier_first_name: string|null, modifier_date: string|null}>, history: list<array{first_name: string, last_name: string, status: string, modifier_first_name: string|null, modifier_date: string|null}>}>}>
     */
    private function buildParticipantStands(int $kermesseId, array $stands, string $timezone): array
    {
        // Aucun stand actif → aucun créneau possible : on évite la lecture des
        // participants (la jointure ne pourrait rien rattacher de toute façon).
        if ($stands === []) {
            return [];
        }

        $signupModel = model(SignupModel::class);

        $participantsBySlot = [];
        foreach ($signupModel->findActiveParticipantsForKermesse($kermesseId) as $p) {
            $modifierFirstName = $p['modifier_first_name'] !== null ? trim((string) $p['modifier_first_name']) : null;
            if ($modifierFirstName === '') {
                $modifierFirstName = 'un administrateur';
            }
            $modifierDate = null;
            if ($modifierFirstName !== null && $p['last_modified_at'] !== null) {
                try {
                    $modifierDate = Time::parse((string) $p['last_modified_at'], $timezone)->format('d/m/Y \à H:i');
                } catch (\Throwable) {
                    log_message('warning', 'Invalid signup modification date for kermesse dashboard.');
                    $modifierFirstName = null;
                }
            }

            // Story 5.10 display rule: use signup's own copy when an admin has written it
            // (null = never touched; '' = admin explicitly cleared). Empty-string values
            // are intentional overrides and must NOT fall back to the global profile.
            $displayFirstName = $p['signup_first_name'] !== null
                ? (string) $p['signup_first_name']
                : (string) $p['first_name'];
            $displayLastName  = $p['signup_last_name'] !== null
                ? (string) $p['signup_last_name']
                : (string) $p['last_name'];
            $displayEmail     = $p['signup_email'] !== null
                ? (string) $p['signup_email']
                : (string) $p['email'];
            $displayPhone     = $p['signup_phone'] !== null
                ? (string) $p['signup_phone']
                : (string) $p['phone'];

            // Story 5.10 (Stateless): if the user has a verified identity (accessed platform or kermesse),
            // we display their global users profile instead of the signup snapshot.
            $locked = $p['first_access_at'] !== null || $p['last_login_at'] !== null;

            // When locked, always show the verified profile (users table), not the signup copy.
            if ($locked) {
                $displayFirstName = (string) $p['first_name'];
                $displayLastName  = (string) $p['last_name'];
                $displayEmail     = (string) $p['email'];
                $displayPhone     = (string) $p['phone'];
            }

            $participantsBySlot[(int) $p['slot_id']][] = [
                'signup_id'           => (int) $p['signup_id'],
                'first_name'          => $displayFirstName,
                'last_name'           => $displayLastName,
                'phone'               => $displayPhone,
                'email'               => $displayEmail,
                'admin_notes'         => (string) $p['admin_notes'],
                'locked'              => $locked,
                'modifier_first_name' => $modifierFirstName,
                'modifier_date'       => $modifierDate,
            ];
        }

        $historicalBySlot = [];
        foreach ($signupModel->findHistoricalParticipantsForKermesse($kermesseId) as $h) {
            $modifierFirstName = $h['modifier_first_name'] !== null ? trim((string) $h['modifier_first_name']) : null;
            if ($modifierFirstName === '') {
                $modifierFirstName = 'un administrateur';
            }
            $modifierDate = null;
            if ($modifierFirstName !== null && $h['last_modified_at'] !== null) {
                try {
                    $modifierDate = Time::parse((string) $h['last_modified_at'], $timezone)->format('d/m/Y \à H:i');
                } catch (\Throwable) {
                    log_message('warning', 'Invalid historical signup date for kermesse dashboard.');
                    $modifierFirstName = null;
                }
            }

            $displayFirstName = $h['signup_first_name'] !== null
                ? (string) $h['signup_first_name']
                : (string) $h['first_name'];
            $displayLastName = $h['signup_last_name'] !== null
                ? (string) $h['signup_last_name']
                : (string) $h['last_name'];

            $historicalBySlot[(int) $h['slot_id']][] = [
                'first_name'          => $displayFirstName,
                'last_name'           => $displayLastName,
                'status'              => (string) $h['status'],
                'modifier_first_name' => $modifierFirstName,
                'modifier_date'       => $modifierDate,
            ];
        }

        $result = [];
        foreach ($stands as $stand) {
            $slots = [];
            foreach ($stand['slots'] ?? [] as $slot) {
                $slotId     = (int) $slot['id'];
                $volunteers = $participantsBySlot[$slotId] ?? [];
                $capacity   = (int) $slot['capacity'];
                $occupied   = count($volunteers);

                $start = Time::parse((string) $slot['starts_at'], $timezone);
                $end   = Time::parse((string) $slot['ends_at'], $timezone);

                $slots[] = [
                    'slot_id'    => $slotId,
                    'date'       => $start->format('d/m/Y'),
                    'start_time' => $start->format('H:i'),
                    'end_time'   => $end->format('H:i'),
                    'capacity'   => $capacity,
                    'occupied'   => $occupied,
                    'remaining'  => max(0, $capacity - $occupied),
                    'volunteers' => $volunteers,
                    'history'    => $historicalBySlot[$slotId] ?? [],
                ];
            }

            $result[] = [
                'name'  => (string) $stand['name'],
                'slots' => $slots,
            ];
        }

        return $result;
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

    /**
     * POST /kermesse/{id}/invitations — invite an Admin/Gestionnaire (Story 4.5).
     *
     * Route is already guarded by role:owner,admin (RBAC); this method only validates
     * input then delegates the whole invariant (user creation, role assignment, token,
     * email) to RoleService::invite — no role mutation happens here (PRG on success).
     */
    public function invite(string $kermesseId): mixed
    {
        $id       = (int) $kermesseId;
        $kermesse = model(KermesseModel::class)->find($id);

        if ($kermesse === null) {
            return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404'));
        }

        // Guard against array-shaped input (email[]=…) which would warn on the string cast.
        $emailInput = $this->request->getPost('email');
        $email      = trim(is_array($emailInput) ? '' : (string) $emailInput);
        $roleInput  = $this->request->getPost('role');
        $role       = is_string($roleInput) ? $roleInput : '';
        $firstNameInput = $this->request->getPost('first_name');
        $firstName      = trim(is_array($firstNameInput) ? '' : (string) $firstNameInput);
        $lastNameInput  = $this->request->getPost('last_name');
        $lastName       = trim(is_array($lastNameInput) ? '' : (string) $lastNameInput);

        $validation = service('validation');
        $isValid    = $validation->setRules([
            'email'      => 'required|valid_email',
            'role'       => 'required|in_list[admin,gestionnaire]',
            'first_name' => 'required|string|max_length[100]',
            'last_name'  => 'required|string|max_length[100]',
        ])->run([
            'email'      => $email,
            'role'       => $role,
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]);

        if (! $isValid) {
            return redirect()->back()
                ->withInput()
                ->with('invite_error', 'Veuillez saisir un prénom, un nom, un email valide et choisir un rôle.');
        }

        $roleService = new RoleService(model(UserRoleModel::class), model(UserModel::class));
        $result      = $roleService->invite($id, $email, $role, (int) session()->get('user_id'), $firstName, $lastName);

        if (! $result->success) {
            $roleLabel = $role === 'admin' ? 'administrateur' : 'gestionnaire';
            $message   = match ($result->errorCode) {
                'cannot_invite_owner' => 'Cette personne est déjà propriétaire de la kermesse ; son rôle ne peut pas être modifié.',
                'already_has_role'    => "Cet utilisateur a déjà le rôle {$roleLabel} sur cette kermesse.",
                'invalid_role'        => 'Le rôle sélectionné est invalide.',
                'invalid_email'       => 'Veuillez saisir un email valide.',
                default               => 'Une erreur est survenue lors de l\'envoi de l\'invitation. Veuillez réessayer.',
            };

            return redirect()->back()->withInput()->with('invite_error', $message);
        }

        // Le rôle est attribué dans tous les cas de succès ; on reste honnête sur l'email :
        // si l'envoi a échoué (tracé dans email_events), on le signale plutôt que de
        // confirmer faussement « Invitation envoyée » (UX-DR20).
        if ($result->emailSent === false) {
            session()->setFlashdata(
                'invite_warning',
                'Le rôle a été attribué à ' . $email . ", mais l'email d'invitation n'a pas pu être envoyé. Vous pouvez réessayer.",
            );
        } else {
            session()->setFlashdata('invite_success', 'Invitation envoyée à ' . $email . '.');
        }

        return redirect()->to(site_url("kermesse/{$id}#participants"));
    }

    public function updateTeamMember(int $kermesseId, int $userId): mixed
    {
        $kermesse = model(KermesseModel::class)->find($kermesseId);
        if ($kermesse === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $roleInput  = trim(is_array($r = $this->request->getPost('role'))       ? '' : (string) $r);
        $emailInput = trim(is_array($e = $this->request->getPost('email'))      ? '' : (string) $e);
        $firstInput = trim(is_array($f = $this->request->getPost('first_name')) ? '' : (string) $f);
        $lastInput  = trim(is_array($l = $this->request->getPost('last_name'))  ? '' : (string) $l);

        $validation = service('validation');
        $isValid = $validation->setRules([
            'role'       => 'required|in_list[admin,gestionnaire]',
            'first_name' => 'permit_empty|string|max_length[100]',
            'last_name'  => 'permit_empty|string|max_length[100]',
            'email'      => "required|valid_email|is_unique[users.email,id,{$userId}]",
        ])->run([
            'role'       => $roleInput,
            'first_name' => $firstInput,
            'last_name'  => $lastInput,
            'email'      => $emailInput,
        ]);

        if (! $isValid) {
            return redirect()->back()->withInput()->with('invite_error', 'Les informations soumises sont invalides.');
        }

        $userRoleModel = model(UserRoleModel::class);
        $roleRow       = $userRoleModel->findByKermesseAndUser($kermesseId, $userId);

        if ($roleRow && (string) $roleRow['role'] !== UserRoleModel::ROLE_OWNER) {
            $userRoleModel->update((int) $roleRow['id'], ['role' => $roleInput]);

            // For pending invitations (not yet accepted), the admin can also correct
            // profile fields — write them to the users table including the email hash.
            if (($roleRow['accepted_at'] ?? null) === null) {
                $userModel     = model(UserModel::class);
                $normalizedEmail = mb_strtolower($emailInput);
                $userModel->update($userId, [
                    'first_name' => $firstInput,
                    'last_name'  => $lastInput,
                    'email'      => $normalizedEmail,
                    'email_hash' => $userModel->hashEmail($normalizedEmail),
                ]);
            }
        }

        return redirect()->to(site_url("kermesse/{$kermesseId}#participants"))
                         ->with('invite_success', 'Membre mis à jour avec succès.');
    }

    public function resendInvite(int $kermesseId, int $userId): mixed
    {
        $kermesse = model(KermesseModel::class)->find($kermesseId);
        if ($kermesse === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $roleService = new RoleService(model(UserRoleModel::class), model(UserModel::class));

        if ($roleService->resendInvitation($kermesseId, $userId)) {
            return redirect()->to(site_url("kermesse/{$kermesseId}#participants"))
                             ->with('invite_success', 'Invitation relancée avec succès.');
        }

        return redirect()->to(site_url("kermesse/{$kermesseId}#participants"))
                         ->with('invite_error', 'Impossible de relancer l\'invitation.');
    }

    public function removeTeamMember(int $kermesseId, int $userId): mixed
    {
        $kermesse = model(KermesseModel::class)->find($kermesseId);
        if ($kermesse === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $roleService = new RoleService(model(UserRoleModel::class), model(UserModel::class));
        $revoked     = $roleService->removeRole($kermesseId, $userId);

        if ($revoked) {
            session()->setFlashdata('invite_success', 'Membre révoqué avec succès.');
        }

        return redirect()->to(site_url("kermesse/{$kermesseId}#participants"));
    }
}
