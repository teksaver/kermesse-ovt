<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Services\EmailService;
use App\Services\TokenService;

/**
 * Universal Magic Link flow: request, send, and validate.
 */
class MagicLinkController extends BaseController
{
    /** GET /auth/login — affiche le formulaire de demande de lien */
    public function showLoginForm(): mixed
    {
        return view('auth/login', ['errors' => []]);
    }

    /** POST /auth/login — valide l'email, émet un Magic Link, affiche la confirmation neutre */
    public function requestLink(): mixed
    {
        $raw = trim((string) $this->request->getPost('email'));

        $validation = service('validation');
        if (! $validation->setRules(['email' => 'required|valid_email'])->run(['email' => $raw])) {
            return view('auth/login', [
                'errors' => $validation->getErrors(),
                'old'    => ['email' => $raw],
            ]);
        }

        $email    = strtolower($raw);
        $issued   = (new TokenService())->issueUserLoginToken($email);
        $loginUrl = site_url('auth/magic-link/' . $issued->rawToken);

        (new EmailService())->sendUserLoginEmail($email, $loginUrl);

        return view('auth/magic_link_sent', ['email' => $email]);
    }

    /** GET /auth/magic-link/{token} — valide le token et crée la session (Story 1.4) */
    public function consume(string $token): mixed
    {
        // TODO: Story 1.4
        return redirect()->to(site_url('/'));
    }
}
