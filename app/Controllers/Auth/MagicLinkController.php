<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

/**
 * Universal Magic Link flow: request, send, and validate.
 * Implemented in Story 1.3.
 */
class MagicLinkController extends BaseController
{
    /** GET /auth/login — affiche le formulaire de demande de lien */
    public function showLoginForm(): mixed
    {
        return view('auth/login');
    }

    /** POST /auth/login — envoie le Magic Link par email */
    public function requestLink(): mixed
    {
        // TODO: Story 1.3
        return redirect()->to(site_url('/'));
    }

    /** GET /auth/magic-link/{token} — valide le token et crée la session */
    public function consume(string $token): mixed
    {
        // TODO: Story 1.4
        return redirect()->to(site_url('/'));
    }
}
