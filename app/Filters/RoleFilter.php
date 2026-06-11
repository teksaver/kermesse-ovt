<?php

namespace App\Filters;

use App\Models\UserRoleModel;
use App\Services\RoleService;
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
        $userId = (int) session()->get('user_id');
        if (! $userId) {
            return redirect()->to(site_url('auth/login'));
        }

        $segments   = $request->getUri()->getSegments();
        $kermesseId = (int) ($segments[1] ?? 0);
        if ($kermesseId <= 0) {
            return $this->forbidden();
        }

        $roleService = new RoleService(model(UserRoleModel::class), model(\App\Models\UserModel::class));
        $role        = $roleService->getRoleForUser($kermesseId, $userId);
        $allowed     = $arguments ?: [
            UserRoleModel::ROLE_OWNER,
            UserRoleModel::ROLE_ADMIN,
            UserRoleModel::ROLE_GESTIONNAIRE,
        ];

        if (! in_array($role, $allowed, true)) {
            return $this->forbidden();
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }

    private function forbidden(): ResponseInterface
    {
        return service('response')->setStatusCode(403)->setBody('Accès refusé');
    }
}
