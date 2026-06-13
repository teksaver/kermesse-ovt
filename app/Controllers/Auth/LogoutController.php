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

        $returnTo = $this->request->getPost('return_to');
        if (is_string($returnTo) && str_starts_with($returnTo, base_url())) {
            return redirect()->to($returnTo);
        }

        return redirect()->to(site_url('/'));
    }
}
