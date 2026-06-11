<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;

/**
 * Connected user home: lists the user's kermesses with their role.
 * Implemented in Story 1.5.
 */
class UserDashboardController extends BaseController
{
    /** GET /dashboard */
    public function index(): mixed
    {
        // TODO: Story 1.5
        return view('dashboard/index');
    }
}
