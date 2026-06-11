<?php

namespace App\Controllers\Home;

use App\Controllers\BaseController;

/**
 * Global home: public landing page (unauthenticated) and connected home (kermesse list).
 * Implemented in Stories 1.2 and 1.5.
 */
class HomeController extends BaseController
{
    /** GET / */
    public function index(): mixed
    {
        // TODO: Story 1.2 (public) / Story 1.5 (connected)
        return view('dashboard/index');
    }
}
