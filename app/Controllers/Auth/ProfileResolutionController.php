<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

/**
 * Profile divergence resolution at login.
 * Implemented in Story 3.6.
 */
class ProfileResolutionController extends BaseController
{
    /** GET /auth/profile-resolution */
    public function show(): mixed
    {
        // TODO: Story 3.6
        return redirect()->to(site_url('/dashboard'));
    }

    /** POST /auth/profile-resolution */
    public function resolve(): mixed
    {
        // TODO: Story 3.6
        return redirect()->to(site_url('/dashboard'));
    }
}
