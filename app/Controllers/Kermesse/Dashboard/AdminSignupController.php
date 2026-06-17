<?php

declare(strict_types=1);

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\AdminCreateSignupDTO;
use App\Services\EmailService;
use App\Services\SignupService;

/**
 * Admin-side signup operations — Story 5.10.
 *
 * adminCancel: Owner/Admin/Gestionnaire cancels any volunteer's signup,
 *   bypassing the kermesse lifecycle check, with an optional email notification.
 * adminEdit: Owner/Admin/Gestionnaire edits a signup's contact fields when the
 *   volunteer has not yet accessed this kermesse (first_access_at IS NULL).
 *
 * All invariants live in SignupService — never in this controller.
 */
class AdminSignupController extends BaseController
{
    /** POST /kermesse/{kermesseId}/slots/{slotId}/admin-add-signup — Story 5.11 */
    public function adminAddSignup(string $kermesseId, string $slotId): mixed
    {
        $kermesseIntId = (int) $kermesseId;
        $slotIntId     = (int) $slotId;
        $adminUserId   = (int) session()->get('user_id');

        $firstNameRaw = $this->request->getPost('first_name');
        $lastNameRaw  = $this->request->getPost('last_name');
        $emailRaw     = $this->request->getPost('email');
        $phoneRaw     = $this->request->getPost('phone');
        $sendEmailRaw = $this->request->getPost('send_confirmation_email');

        $firstName = trim(is_array($firstNameRaw) ? '' : (string) $firstNameRaw);
        $lastName  = trim(is_array($lastNameRaw)  ? '' : (string) $lastNameRaw);
        $email     = trim(is_array($emailRaw)     ? '' : (string) $emailRaw);
        $phone     = trim(is_array($phoneRaw)     ? '' : (string) $phoneRaw);
        $sendEmail = ! empty($sendEmailRaw);

        $validation = service('validation');
        $isValid    = $validation->setRules([
            'first_name' => 'required|string|min_length[1]|max_length[100]',
            'last_name'  => 'required|string|min_length[1]|max_length[100]',
            'email'      => 'required|valid_email|max_length[255]',
            'phone'      => 'permit_empty|string|max_length[30]',
        ])->run([
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'phone'      => $phone,
        ]);

        if (! $isValid) {
            session()->setFlashdata('participants_error', 'Prénom, nom et email (valide) sont obligatoires.');
            session()->setFlashdata('participants_add_errors', $validation->getErrors());

            return redirect()
                ->to(site_url("kermesse/{$kermesseIntId}#inscrits"))
                ->withInput();
        }

        $dto = new AdminCreateSignupDTO(
            slotId:                $slotIntId,
            kermesseId:            $kermesseIntId,
            adminUserId:           $adminUserId,
            firstName:             $firstName,
            lastName:              $lastName,
            email:                 $email,
            phone:                 $phone,
            sendConfirmationEmail: $sendEmail,
        );

        $service = new SignupService(
            userModel:     model(UserModel::class),
            signupModel:   model(SignupModel::class),
            kermesseModel: model(KermesseModel::class),
            emailService:  new EmailService(),
            userRoleModel: model(UserRoleModel::class),
        );

        $result = $service->createSignupByAdmin($dto);

        if ($result->success) {
            $msg = sprintf(
                '%s %s a été inscrit(e) au créneau.',
                $firstName,
                $lastName,
            );

            if ($sendEmail && $result->emailSent === true) {
                $msg .= ' Un email de confirmation lui a été envoyé.';
            } elseif ($sendEmail && $result->emailSent === false) {
                $msg .= " (L'email de confirmation n'a pas pu être envoyé.)";
            } elseif ($sendEmail && $result->emailSent === null) {
                $msg .= " (L'email de confirmation n'a pas pu être envoyé — données du créneau manquantes.)";
            }

            session()->setFlashdata('participants_success', $msg);
        } else {
            $msg = match ($result->errorCode) {
                'slot_full'        => 'Ce créneau est complet. Impossible d\'ajouter un bénévole.',
                'slot_unavailable' => 'Ce créneau n\'est plus disponible (passé ou désactivé).',
                'duplicate_signup' => 'Ce bénévole est déjà inscrit à ce créneau.',
                'overlap_conflict' => 'Ce bénévole a déjà un créneau qui chevauche celui-ci.',
                default            => "L'inscription n'a pas pu être créée. Veuillez réessayer.",
            };

            session()->setFlashdata('participants_error', $msg);

            return redirect()
                ->to(site_url("kermesse/{$kermesseIntId}#inscrits"))
                ->withInput();
        }

        return redirect()->to(site_url("kermesse/{$kermesseIntId}#inscrits"));
    }

    /** POST /kermesse/{kermesseId}/signups/{signupId}/admin-cancel */
    public function adminCancel(string $kermesseId, string $signupId): mixed
    {
        $kermesseIntId = (int) $kermesseId;
        $signupIntId   = (int) $signupId;
        $adminUserId   = (int) session()->get('user_id');

        $notifyRaw = $this->request->getPost('notify');
        $notify    = $notifyRaw === '1' || $notifyRaw === 'on' || $notifyRaw === true;

        $service = new SignupService(
            userModel:     model(UserModel::class),
            signupModel:   model(SignupModel::class),
            kermesseModel: model(KermesseModel::class),
            emailService:  new EmailService(),
            userRoleModel: model(UserRoleModel::class),
        );

        $result = $service->adminCancelSignup(
            signupId:    $signupIntId,
            adminUserId: $adminUserId,
            kermesseId:  $kermesseIntId,
            notify:      $notify,
        );

        if ($result->success) {
            $volunteerName = (string) ($result->context['volunteer_name'] ?? '');
            $slotLabel     = (string) ($result->context['slot_label']     ?? '');

            if ($volunteerName !== '' && $slotLabel !== '') {
                $msg = sprintf("L'inscription de %s au créneau %s a été annulée.", $volunteerName, $slotLabel);
            } elseif ($volunteerName !== '') {
                $msg = sprintf("L'inscription de %s a été annulée.", $volunteerName);
            } else {
                $msg = 'L\'inscription a été annulée.';
            }

            if ($notify && $result->emailSent === true) {
                $msg .= ' Le bénévole a été notifié par email.';
            } elseif ($notify && $result->emailSent === false) {
                $msg .= ' (L\'email de notification n\'a pas pu être envoyé.)';
            }
            session()->setFlashdata('participants_success', $msg);
        } elseif ($result->errorCode === 'not_found') {
            session()->setFlashdata('participants_error', 'Cette inscription est introuvable ou déjà annulée.');
        } else {
            session()->setFlashdata('participants_error', "L'annulation a échoué. Veuillez réessayer.");
        }

        return redirect()->to(site_url("kermesse/{$kermesseIntId}#inscrits"));
    }

    /** POST /kermesse/{kermesseId}/signups/{signupId}/admin-edit */
    public function adminEdit(string $kermesseId, string $signupId): mixed
    {
        $kermesseIntId = (int) $kermesseId;
        $signupIntId   = (int) $signupId;
        $adminUserId   = (int) session()->get('user_id');

        $firstNameRaw = $this->request->getPost('first_name');
        $lastNameRaw  = $this->request->getPost('last_name');
        $emailRaw     = $this->request->getPost('email');
        $phoneRaw     = $this->request->getPost('phone');
        $adminNotesRaw = $this->request->getPost('admin_notes');

        // Trim only — email normalization (lowercase) is the service's responsibility.
        $firstName  = trim(is_array($firstNameRaw) ? '' : (string) $firstNameRaw);
        $lastName   = trim(is_array($lastNameRaw)  ? '' : (string) $lastNameRaw);
        $email      = trim(is_array($emailRaw)     ? '' : (string) $emailRaw);
        $phone      = trim(is_array($phoneRaw)     ? '' : (string) $phoneRaw);
        $adminNotes = trim(is_array($adminNotesRaw) ? '' : (string) $adminNotesRaw);

        $validation = service('validation');
        $isValid    = $validation->setRules([
            // First and last name are required — an admin must not be able to submit
            // an empty name and silently wipe the volunteer's identity in the signup.
            'first_name' => 'required|string|min_length[1]|max_length[100]',
            'last_name'  => 'required|string|min_length[1]|max_length[100]',
            'email'       => 'permit_empty|valid_email|max_length[255]',
            'phone'       => 'permit_empty|string|max_length[30]',
            'admin_notes' => 'permit_empty|string|max_length[5000]',
        ])->run([
            'first_name'  => $firstName,
            'last_name'   => $lastName,
            'email'       => $email,
            'phone'       => $phone,
            'admin_notes' => $adminNotes,
        ]);

        if (! $isValid) {
            session()->setFlashdata('participants_error', 'Prénom et nom sont obligatoires et les autres champs doivent être valides.');

            return redirect()->back()->withInput();
        }

        $service = new SignupService(
            userModel:     model(UserModel::class),
            signupModel:   model(SignupModel::class),
            kermesseModel: model(KermesseModel::class),
            userRoleModel: model(UserRoleModel::class),
        );

        $result = $service->adminEditSignup(
            signupId:    $signupIntId,
            adminUserId: $adminUserId,
            kermesseId:  $kermesseIntId,
            fields:      ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone, 'admin_notes' => $adminNotes],
        );

        if ($result->success) {
            session()->setFlashdata('participants_success', 'La fiche d\'inscription a été mise à jour.');
        } else {
            session()->setFlashdata('participants_error', "La mise à jour a échoué. Veuillez réessayer.");
        }

        return redirect()->to(site_url("kermesse/{$kermesseIntId}#inscrits"));
    }
}
