<?php

declare(strict_types=1);

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\RoleService;

/**
 * Lets any kermesse member (Admin/Gestionnaire/Bénévole) leave a kermesse entirely
 * from the connected home or the dashboard "Mes participations" section (Story 5.9).
 *
 * Single responsibility: translate the POST into a RoleService::leaveKermesse() call
 * and PRG-redirect with a French flash message. All invariants (Owner block, active
 * signups barrier, idempotence) live in the service — never here.
 */
class LeaveKermesseController extends BaseController
{
    /** POST /kermesse/{kermesseId}/leave */
    public function leave(string $kermesseId): mixed
    {
        $id     = (int) $kermesseId;
        $userId = (int) session()->get('user_id');

        $service = new RoleService(model(UserRoleModel::class), model(UserModel::class));
        $result  = $service->leaveKermesse($id, $userId);

        if ($result->success) {
            session()->setFlashdata('participation_notice', 'Vous avez quitté la kermesse.');
        } elseif ($result->errorCode === 'has_active_signups') {
            session()->setFlashdata('participation_error', 'Vous avez encore des inscriptions actives sur cette kermesse. Annulez-les avant de la quitter.');
        } else {
            // unauthorized_role: Owner or missing row (defense in depth after filter).
            session()->setFlashdata('participation_error', 'En tant que propriétaire, vous ne pouvez pas quitter cette kermesse.');
        }

        return redirect()->to(site_url('/'));
    }
}
