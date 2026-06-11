<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ---------------------------------------------------------------------------
// Home
// ---------------------------------------------------------------------------
$routes->get('/', '\App\Controllers\Home\HomeController::index');

// ---------------------------------------------------------------------------
// Auth — universal Magic Link (Stories 1.3, 1.4, 1.5, 3.6)
// ---------------------------------------------------------------------------
$routes->get('auth/login', '\App\Controllers\Auth\MagicLinkController::showLoginForm');
$routes->post('auth/login', '\App\Controllers\Auth\MagicLinkController::requestLink');
$routes->get('auth/magic-link/(:segment)', '\App\Controllers\Auth\MagicLinkController::verify/$1');
$routes->post('auth/logout', '\App\Controllers\Auth\LogoutController::logout');
$routes->get('auth/profile-resolution', '\App\Controllers\Auth\ProfileResolutionController::show');
$routes->post('auth/profile-resolution', '\App\Controllers\Auth\ProfileResolutionController::resolve');

// ---------------------------------------------------------------------------
// Connected home — kermesse list (Story 1.5)
// ---------------------------------------------------------------------------
$routes->get('dashboard', '\App\Controllers\Kermesse\Dashboard\UserDashboardController::index', ['filter' => 'auth']);

// ---------------------------------------------------------------------------
// Kermesse dashboard — admin/management (Stories 2.x, 4.x)
// ---------------------------------------------------------------------------
$routes->get('kermesse/(:num)', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::show/$1', ['filter' => 'role']);

// ---------------------------------------------------------------------------
// Public volunteer page & signup (Stories 3.1–3.5)
// ---------------------------------------------------------------------------
$routes->get('k/(:segment)', '\App\Controllers\Kermesse\Public\PublicController::index/$1');
$routes->get('k/(:segment)/slots/(:num)/signup', '\App\Controllers\Kermesse\Public\SignupController::show/$1/$2');
$routes->post('k/(:segment)/slots/(:num)/signup', '\App\Controllers\Kermesse\Public\SignupController::submit/$1/$2');
$routes->get('k/(:segment)/slots/(:num)/signup/confirmation', '\App\Controllers\Kermesse\Public\SignupController::confirm/$1/$2');

// ---------------------------------------------------------------------------
// Ops endpoints — protected by HMAC authentication, CSRF excluded
// ---------------------------------------------------------------------------
$routes->post('ops/migrate',        '\App\Controllers\Ops\MigrationController::migrate',   ['filter' => 'ops-auth']);
$routes->post('ops/migrate/status', '\App\Controllers\Ops\MigrationController::status',    ['filter' => 'ops-auth']);
$routes->post('ops/probe',          '\App\Controllers\Ops\ProbeController::probe',          ['filter' => 'ops-auth']);
$routes->post('ops/activate',       '\App\Controllers\Ops\ActivateController::activate',   ['filter' => 'ops-auth']);
