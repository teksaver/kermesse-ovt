<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Owner creation routes
$routes->get('create', '\App\Controllers\Owner\CreateKermesseController::showCreateForm');
$routes->post('kermesses', '\App\Controllers\Owner\CreateKermesseController::create');

// Owner validation — token comes from the email link (GET only, no CSRF needed)
$routes->get('owner/validate/(:segment)', '\App\Controllers\Owner\ValidationController::handleValidation/$1');

// Owner login / resend validation link (CSRF active on POST)
$routes->get('owner/login', '\App\Controllers\Owner\LoginController::showLoginForm');
$routes->post('owner/login', '\App\Controllers\Owner\LoginController::requestLink');
// Owner login token consumption (GET only, no CSRF — the secret IS the token in the URL)
$routes->get('owner/login/(:segment)', '\App\Controllers\Owner\LoginController::consumeLoginToken/$1');

// Admin minimal — protected by session-based checks in DashboardController
$routes->get('admin/kermesses/(:num)', '\App\Controllers\Admin\DashboardController::show/$1');

// Ops endpoints — protected by HMAC authentication, CSRF excluded
$routes->post('ops/migrate', '\App\Controllers\Ops\MigrationController::migrate', ['filter' => 'ops-auth']);
