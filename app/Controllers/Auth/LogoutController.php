<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

/**
 * Session logout.
 * Implemented in Story 1.5.
 */
class LogoutController extends BaseController
{
    /** POST /auth/logout */
    public function logout(): mixed
    {
        // TODO: Story 1.5
        session()->destroy();
        return redirect()->to(site_url('/'));
    }
}
