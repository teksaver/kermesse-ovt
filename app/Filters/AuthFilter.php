<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a valid global Magic Link session.
 * Implemented in Story 1.4.
 */
class AuthFilter implements FilterInterface
{
    use BuildsLoginRedirect;

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $userId = session()->get('user_id');
        if (! $userId) {
            return redirect()->to($this->loginRedirectUrl($this->currentRequestPath($request)));
        }

        $userModel = new \App\Models\UserModel();
        if (! $userModel->find($userId)) {
            session()->remove('user_id');
            return redirect()->to($this->loginRedirectUrl($this->currentRequestPath($request)));
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
