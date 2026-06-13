<?php

declare(strict_types=1);

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\UserModel;
use App\Services\SignupService;

/**
 * Lets a volunteer cancel (withdraw from) their OWN signup from the dashboard's
 * "Mes participations" section (Story 4.3).
 *
 * Single responsibility: translate the POST into a SignupService::cancelSignup()
 * call and PRG-redirect with a French flash message. All invariants (ownership,
 * kermesse-open, slot recovery) live in the service — never here.
 */
class SignupCancellationController extends BaseController
{
    /** POST /kermesse/{kermesseId}/signups/{signupId}/cancel */
    public function cancel(string $kermesseId, string $signupId): mixed
    {
        $id     = (int) $kermesseId;
        $userId = (int) session()->get('user_id');

        $service = new SignupService(
            model(UserModel::class),
            model(SignupModel::class),
            model(KermesseModel::class),
        );

        $result = $service->cancelSignup((int) $signupId, $userId, $id);

        if ($result->success) {
            session()->setFlashdata('participation_notice', 'La place est de nouveau disponible.');
        } elseif ($result->errorCode === 'signups_not_open') {
            session()->setFlashdata('participation_error', 'Les inscriptions sont fermées.');
        } else {
            session()->setFlashdata('participation_error', "Cette participation n'a pas pu être annulée.");
        }

        return redirect()->to(site_url("kermesse/{$id}"));
    }
}
