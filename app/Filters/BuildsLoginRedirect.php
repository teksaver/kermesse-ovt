<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Shared login-redirect construction for the auth-related filters.
 *
 * Centralises the `/auth/login?redirect=<path>` URL building so AuthFilter
 * (GET interception) and CsrfFilter (expired-session POST) cannot drift apart.
 */
trait BuildsLoginRedirect
{
    /**
     * Build the login URL, optionally carrying a same-origin resume path so the
     * user is returned to their page after re-authenticating.
     */
    protected function loginRedirectUrl(?string $resumePath): string
    {
        $url = site_url('auth/login');

        if ($resumePath !== null && $resumePath !== '') {
            $url .= '?redirect=' . urlencode($resumePath);
        }

        return $url;
    }

    /**
     * Extract the path (plus query string) of the current request, suitable as a
     * resume target after login. Safe to replay because the original request is a GET.
     */
    protected function currentRequestPath(RequestInterface $request): string
    {
        $uri  = $request->getUri();
        $path = $uri->getPath();

        $query = $uri->getQuery();
        if ($query !== '') {
            $path .= '?' . $query;
        }

        return $path;
    }
}
