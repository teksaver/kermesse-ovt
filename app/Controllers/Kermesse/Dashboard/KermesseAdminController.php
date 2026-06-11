<?php

namespace App\Controllers\Kermesse\Dashboard;

use App\Controllers\BaseController;

/**
 * Kermesse admin dashboard: stands, slots, lifecycle, participants.
 * Implemented in Stories 2.1–2.5 and 4.4.
 */
class KermesseAdminController extends BaseController
{
    /** GET /kermesse/{id} */
    public function show(string $kermesseId): mixed
    {
        // TODO: Stories 2.x, 4.x
        return redirect()->to(site_url('/dashboard'));
    }
}
