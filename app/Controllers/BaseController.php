<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    protected function localRedirectTarget(mixed $candidate, ?string $fallback = null): string
    {
        $fallback ??= site_url('/');

        if (! is_string($candidate) || trim($candidate) === '') {
            return $fallback;
        }

        $candidate = trim($candidate);
        if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            return $this->isAuthRoute($candidate) ? $fallback : site_url(ltrim($candidate, '/'));
        }

        $baseParts      = parse_url(site_url('/'));
        $candidateParts = parse_url($candidate);

        if (
            is_array($baseParts)
            && is_array($candidateParts)
            && ($candidateParts['scheme'] ?? null) === ($baseParts['scheme'] ?? null)
            && ($candidateParts['host'] ?? null) === ($baseParts['host'] ?? null)
            && ($candidateParts['port'] ?? null) === ($baseParts['port'] ?? null)
        ) {
            return $this->isAuthRoute($candidateParts['path'] ?? '') ? $fallback : $candidate;
        }

        return $fallback;
    }

    /**
     * Reject redirect targets that point back at an auth route (login, magic-link).
     * Resuming onto one after authentication would re-enter the login flow in a loop.
     */
    private function isAuthRoute(string $path): bool
    {
        return (bool) preg_match('#(^|/)auth(/|$)#', $path);
    }
}
