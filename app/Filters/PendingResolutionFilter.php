<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Redirects authenticated users to the profile resolution page when they have
 * a first-login confirmation pending (Story 5.4) or unresolved divergences (Story 3.6).
 * Applied to connected routes.
 */
class PendingResolutionFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        if (! session()->get('user_id')) {
            return null;
        }

        if (
            session()->get('pending_first_login_confirmation') === true
            || session()->get('pending_profile_resolution') === true
        ) {
            return redirect()->to(site_url('auth/profile-resolution'));
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
