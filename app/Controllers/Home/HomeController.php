<?php

namespace App\Controllers\Home;

use App\Controllers\BaseController;
use App\Models\UserRoleModel;

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

        $kermesses = model(UserRoleModel::class)->findKermessesForUser($userId);

        return view('home/connected', [
            'title'      => 'Mes kermesses',
            'kermesses'  => $kermesses,
            'roleLabels' => $roleLabels,
        ]);
    }
}
