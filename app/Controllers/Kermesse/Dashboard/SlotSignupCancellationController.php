<?php

declare(strict_types=1);

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\SlotSignupModel;
use App\Models\UserModel;
use App\Services\SlotSignupService;

/**
 * Lets a volunteer manage their OWN slot-signups from the dashboard's "Mes participations"
 * section (Stories 4.3, 5.14).
 *
 * Single responsibility: translate the POST into SlotSignupService calls and
 * PRG-redirect with a French flash message. All invariants live in the service.
 */
class SlotSignupCancellationController extends BaseController
{
    /** POST /kermesse/{kermesseId}/signups/{signupId}/cancel */
    public function cancel(string $kermesseId, string $signupId): mixed
    {
        $id     = (int) $kermesseId;
        $userId = (int) session()->get('user_id');

        $service = new SlotSignupService(
            model(UserModel::class),
            model(SlotSignupModel::class),
            model(KermesseModel::class),
        );

        $result = $service->cancelSlotSignup((int) $signupId, $userId, $id);

        if ($result->success) {
            session()->setFlashdata('participation_notice', 'La place est de nouveau disponible.');
        } elseif ($result->errorCode === 'signups_not_open') {
            session()->setFlashdata('participation_error', 'Les inscriptions sont fermées.');
        } else {
            session()->setFlashdata('participation_error', "Cette participation n'a pas pu être annulée.");
        }

        return redirect()->to(site_url("kermesse/{$id}#participations"));
    }

    /** POST /kermesse/{kermesseId}/signups/{signupId}/accept — Story 5.14 AC3 */
    public function accept(string $kermesseId, string $signupId): mixed
    {
        $id     = (int) $kermesseId;
        $userId = (int) session()->get('user_id');

        $service = new SlotSignupService(
            model(UserModel::class),
            model(SlotSignupModel::class),
            model(KermesseModel::class),
        );

        $result = $service->acceptSlotSignup((int) $signupId, $userId, $id);

        if ($result->success) {
            session()->setFlashdata('participation_notice', 'Votre participation a été confirmée.');
        } else {
            session()->setFlashdata('participation_error', "La confirmation n'a pas pu être enregistrée.");
        }

        return redirect()->to(site_url("kermesse/{$id}#participations"));
    }

    /** POST /kermesse/{kermesseId}/signups/{signupId}/reject — Story 5.14 AC4 */
    public function reject(string $kermesseId, string $signupId): mixed
    {
        $id     = (int) $kermesseId;
        $userId = (int) session()->get('user_id');

        $service = new SlotSignupService(
            model(UserModel::class),
            model(SlotSignupModel::class),
            model(KermesseModel::class),
        );

        $result = $service->rejectSlotSignup((int) $signupId, $userId, $id);

        if ($result->success) {
            session()->setFlashdata('participation_notice', 'Votre refus a été enregistré. La place a été libérée.');
        } else {
            session()->setFlashdata('participation_error', "Le refus n'a pas pu être enregistré.");
        }

        return redirect()->to(site_url("kermesse/{$id}#participations"));
    }
}
