<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Ops endpoints — protected by HMAC authentication, CSRF excluded
$routes->post('ops/migrate', '\App\Controllers\Ops\MigrationController::migrate', ['filter' => 'ops-auth']);
