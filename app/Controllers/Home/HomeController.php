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
        // Story 1.5 will add session check to redirect connected users to dashboard.
        return view('home/public', ['title' => 'Kermesse']);
    }
}
