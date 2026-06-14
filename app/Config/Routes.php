<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ---------------------------------------------------------------------------
// Home
// ---------------------------------------------------------------------------
$routes->get('/', '\App\Controllers\Home\HomeController::index', ['filter' => 'pending-resolution']);

// ---------------------------------------------------------------------------
// Auth — universal Magic Link (Stories 1.3, 1.4, 1.5, 3.6)
// ---------------------------------------------------------------------------
$routes->get('auth/login', '\App\Controllers\Auth\MagicLinkController::showLoginForm', ['as' => 'auth.login']);
$routes->post('auth/login', '\App\Controllers\Auth\MagicLinkController::requestLink');
$routes->get('auth/magic-link/(:segment)', '\App\Controllers\Auth\MagicLinkController::verify/$1');
$routes->post('auth/logout', '\App\Controllers\Auth\LogoutController::logout');
$routes->get('auth/profile-resolution', '\App\Controllers\Auth\ProfileResolutionController::show', ['filter' => 'auth']);
$routes->post('auth/profile-resolution', '\App\Controllers\Auth\ProfileResolutionController::resolve', ['filter' => 'auth']);

// ---------------------------------------------------------------------------
// Connected home — kermesse list (Story 1.5)
// ---------------------------------------------------------------------------
$routes->get('dashboard', '\App\Controllers\Kermesse\Dashboard\UserDashboardController::index', ['filter' => ['auth', 'pending-resolution']]);

// ---------------------------------------------------------------------------
// Profile management
// ---------------------------------------------------------------------------
$routes->get('profile', '\App\Controllers\ProfileController::edit', ['filter' => ['auth', 'pending-resolution']]);
$routes->post('profile', '\App\Controllers\ProfileController::update', ['filter' => ['auth', 'pending-resolution']]);

// ---------------------------------------------------------------------------
// Kermesse creation — open to all (Story 2.1)
// ---------------------------------------------------------------------------
$routes->get('kermesse/create', '\App\Controllers\Kermesse\KermesseController::create');
$routes->post('kermesses', '\App\Controllers\Kermesse\KermesseController::store');

// ---------------------------------------------------------------------------
// Kermesse dashboard — admin/management (Stories 2.x, 4.x)
// ---------------------------------------------------------------------------
// Tableau de bord interne accessible à tout rôle (Story 4.1) ; les sections
// internes sont gardées par rôle côté serveur dans le contrôleur/la vue.
$routes->get('kermesse/(:num)', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::show/$1', ['filter' => 'role:owner,admin,gestionnaire,benevole']);
$routes->post('kermesse/(:num)/edit', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::update/$1', ['filter' => 'role:owner,admin']);

// Invitation d'un Admin/Gestionnaire (Story 4.5) — réservé Owner/Admin (RBAC route + service).
$routes->post('kermesse/(:num)/invitations', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::invite/$1', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/team/(:num)/edit', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::updateTeamMember/$1/$2', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/team/(:num)/resend', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::resendInvite/$1/$2', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/team/(:num)/delete', '\App\Controllers\Kermesse\Dashboard\KermesseAdminController::removeTeamMember/$1/$2', ['filter' => 'role:owner,admin']);

// Désistement bénévole : annuler sa propre inscription depuis « Mes participations »
// (Story 4.3). Ouvert à tout rôle membre ; l'ownership est garanti côté service.
$routes->post('kermesse/(:num)/signups/(:num)/cancel', '\App\Controllers\Kermesse\Dashboard\SignupCancellationController::cancel/$1/$2', ['filter' => 'role:owner,admin,gestionnaire,benevole']);

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
$routes->post('kermesse/(:num)/stands/(:num)/duplicate', '\App\Controllers\Kermesse\Dashboard\StandController::duplicate/$1/$2', ['filter' => 'role:owner,admin']);

// ---------------------------------------------------------------------------
// Slot management — Owner/Admin only (Story 2.3)
// ---------------------------------------------------------------------------
$routes->post('kermesse/(:num)/stands/(:num)/slots', '\App\Controllers\Kermesse\Dashboard\SlotController::store/$1/$2', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/slots/(:num)', '\App\Controllers\Kermesse\Dashboard\SlotController::update/$1/$2', ['filter' => 'role:owner,admin']);
$routes->post('kermesse/(:num)/slots/(:num)/delete', '\App\Controllers\Kermesse\Dashboard\SlotController::delete/$1/$2', ['filter' => 'role:owner,admin']);

// ---------------------------------------------------------------------------
// Public volunteer page & signup (Stories 3.1–3.5)
// ---------------------------------------------------------------------------
$routes->get('k/(:segment)', '\App\Controllers\Kermesse\Public\PublicController::index/$1');
$routes->get('k/(:segment)/slots/(:num)/signup', '\App\Controllers\Kermesse\Public\SignupController::show/$1/$2');
$routes->post('k/(:segment)/slots/(:num)/signup', '\App\Controllers\Kermesse\Public\SignupController::submit/$1/$2');
$routes->post('k/(:segment)/slots/(:num)/signup/forget', '\App\Controllers\Kermesse\Public\SignupController::forget/$1/$2');
$routes->get('k/(:segment)/slots/(:num)/signup/confirmation', '\App\Controllers\Kermesse\Public\SignupController::confirm/$1/$2');

// ---------------------------------------------------------------------------
// Ops endpoints — protected by HMAC authentication, CSRF excluded
// ---------------------------------------------------------------------------
$routes->post('ops/migrate',        '\App\Controllers\Ops\MigrationController::migrate',   ['filter' => 'ops-auth']);
$routes->post('ops/migrate/status', '\App\Controllers\Ops\MigrationController::status',    ['filter' => 'ops-auth']);
$routes->post('ops/probe',          '\App\Controllers\Ops\ProbeController::probe',          ['filter' => 'ops-auth']);
$routes->post('ops/activate',       '\App\Controllers\Ops\ActivateController::activate',   ['filter' => 'ops-auth']);
