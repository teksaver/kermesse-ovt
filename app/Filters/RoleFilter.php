<?php

namespace App\Filters;

use App\Models\UserRoleModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires the authenticated user to hold a minimum role on the kermesse.
 * The kermesse_id is extracted from the first route segment after /kermesse/.
 * Implemented in Stories 2.x.
 */
class RoleFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments  Allowed roles (e.g. ['owner','admin'])
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $userId = session()->get('user_id');
        if (! $userId) {
            return redirect()->to(site_url('auth/login'));
        }

        // TODO: Stories 2.x — extract kermesse_id from route and enforce role
        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
