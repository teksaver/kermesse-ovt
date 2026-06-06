<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', '\App\Controllers\Owner\CreateKermesseController::showCreateForm');

// Owner creation routes
$routes->get('create', '\App\Controllers\Owner\CreateKermesseController::showCreateForm');
$routes->post('kermesses', '\App\Controllers\Owner\CreateKermesseController::create');

// Owner validation — token comes from the email link (GET only, no CSRF needed)
$routes->get('owner/validate/(:segment)', '\App\Controllers\Owner\ValidationController::handleValidation/$1');

// Owner login / resend validation link (CSRF active on POST)
$routes->get('owner/login', '\App\Controllers\Owner\LoginController::showLoginForm');
$routes->post('owner/login', '\App\Controllers\Owner\LoginController::requestLink');
// Owner login token consumption — GET validates and shows confirmation (prevents prefetch consumption).
// POST /owner/login/confirm performs the actual session-based consumption (no token in HTML).
// POST /owner/login/(:segment) kept for direct invocation (tests, API callers).
$routes->get('owner/login/(:segment)', '\App\Controllers\Owner\LoginController::showLoginConfirm/$1');
$routes->post('owner/login/confirm', '\App\Controllers\Owner\LoginController::confirmLogin');
$routes->post('owner/login/(:segment)', '\App\Controllers\Owner\LoginController::consumeLoginToken/$1');

// Admin minimal — protected by session-based checks in DashboardController
$routes->get('admin/kermesses/(:num)', '\App\Controllers\Admin\DashboardController::show/$1');

// Admin lifecycle — open/close signups, CSRF active on POST
$routes->post('admin/kermesses/(:num)/open', '\App\Controllers\Admin\KermesseController::open/$1');
$routes->post('admin/kermesses/(:num)/close', '\App\Controllers\Admin\KermesseController::close/$1');

// Admin read-only preview of the planning (not the public volunteer page)
$routes->get('admin/kermesses/(:num)/preview', '\App\Controllers\Admin\PreviewController::show/$1');

// Admin stands — state-changing routes, CSRF active
$routes->post('admin/kermesses/(:num)/stands', '\App\Controllers\Admin\StandController::create/$1');
$routes->post('admin/kermesses/(:num)/stands/(:num)', '\App\Controllers\Admin\StandController::update/$1/$2');
$routes->post('admin/kermesses/(:num)/stands/(:num)/delete', '\App\Controllers\Admin\StandController::delete/$1/$2');

// Admin slots — state-changing routes, CSRF active
$routes->post('admin/kermesses/(:num)/stands/(:num)/slots', '\App\Controllers\Admin\SlotController::create/$1/$2');
$routes->post('admin/kermesses/(:num)/stands/(:num)/slots/(:num)', '\App\Controllers\Admin\SlotController::update/$1/$2/$3');

// Ops endpoints — protected by HMAC authentication, CSRF excluded
$routes->post('ops/migrate', '\App\Controllers\Ops\MigrationController::migrate', ['filter' => 'ops-auth']);
$routes->post('ops/probe', '\App\Controllers\Ops\ProbeController::probe', ['filter' => 'ops-auth']);
