<?php

namespace App\Controllers\Home;

use App\Controllers\BaseController;
use App\Models\KermesseModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\RoleService;

/**
 * Global home: public landing page (unauthenticated) and connected home (kermesse list).
 * Implemented in Stories 1.2 and 1.5.
 */
class HomeController extends BaseController
{
    /** GET / */
    public function index(): string
    {
        $userId = (int) session()->get('user_id');

        if ($userId <= 0) {
            return view('home/public', ['title' => 'Padlapin']);
        }

        $roleLabels = [
            UserRoleModel::ROLE_OWNER        => 'Organisateur',
            UserRoleModel::ROLE_ADMIN        => 'Administrateur',
            UserRoleModel::ROLE_GESTIONNAIRE => 'Gestionnaire',
            UserRoleModel::ROLE_BENEVOLE     => 'Bénévole',
        ];
        $statusLabels = [
            KermesseModel::STATUS_PREPARATION => 'En préparation',
            KermesseModel::STATUS_OPEN        => 'Inscriptions ouvertes',
            KermesseModel::STATUS_CLOSED      => 'Inscriptions fermées',
        ];

        $kermesses   = model(UserRoleModel::class)->findKermessesForUser($userId);
        $roleService = new RoleService(model(UserRoleModel::class), model(UserModel::class));

        foreach ($kermesses as &$k) {
            $status = (string) ($k['status'] ?? '');

            $k['canLeave']     = $roleService->canLeaveKermesse((int) $k['id'], $userId);
            $k['status_label'] = $statusLabels[$status] ?? ucfirst($status);
        }
        unset($k);

        return view('home/connected', [
            'title'      => 'Mes kermesses',
            'kermesses'  => $kermesses,
            'roleLabels' => $roleLabels,
        ]);
    }
}
