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
$routes->get('auth/login', '\App\Controllers\Auth\MagicLinkController::showLoginForm', ['as' => 'auth.login']);
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
// Kermesse creation — open to all (Story 2.1)
// ---------------------------------------------------------------------------
$routes->get('kermesse/create', '\App\Controllers\Kermesse\KermesseController::create');
$routes->post('kermesses', '\App\Controllers\Kermesse\KermesseController::store');

// ---------------------------------------------------------------------------
// Kermesse dashboard — admin/management (Stories 2.x, 4.x)
// ---------------------------------------------------------------------------
$routes->get('kermesse/(:num)', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::show/$1', ['filter' => 'role']);

// ---------------------------------------------------------------------------
// Lifecycle management — Owner/Admin only (Story 2.5)
// ---------------------------------------------------------------------------
$routes->post('kermesse/(:num)/open',  '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::open/$1',  ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/close', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::close/$1', ['filter' => 'role:owner,admin']);

// ---------------------------------------------------------------------------
// Stand management — Owner/Admin only (Stories 2.2, 2.4)
// ---------------------------------------------------------------------------
$routes->post('kermesse/(:num)/stands', '\App\Controllers\Kermesse\Dashboard\StandController::store/$1', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/stands/(:num)', '\App\Controllers\Kermesse\Dashboard\StandController::update/$1/$2', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/stands/(:num)/delete', '\App\Controllers\Kermesse\Dashboard\StandController::delete/$1/$2', ['filter' => 'role:owner,admin']);

// ---------------------------------------------------------------------------
// Slot management — Owner/Admin only (Story 2.3)
// ---------------------------------------------------------------------------
$routes->post('kermesse/(:num)/stands/(:num)/slots', '\App\Controllers\Kermesse\Dashboard\SlotController::store/$1/$2', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/slots/(:num)', '\App\Controllers\Kermesse\Dashboard\SlotController::update/$1/$2', ['filter' => 'role:owner,admin']);

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
