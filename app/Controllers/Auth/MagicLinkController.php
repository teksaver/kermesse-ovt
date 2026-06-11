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
        // Guard against array-shaped input (email[]=...) which would otherwise trigger
        // an "Array to string conversion" warning on the cast (cf. SignupController).
        $emailInput = $this->request->getPost('email');
        $raw        = trim(is_array($emailInput) ? '' : (string) $emailInput);

        $validation = service('validation');
        if (! $validation->setRules(['email' => 'required|valid_email'])->run(['email' => $raw])) {
            return view('auth/login', [
                'errors' => $validation->getErrors(),
                'old'    => ['email' => $raw],
            ]);
        }

        $email    = strtolower($raw);
        $issued   = (new TokenService())->issueMagicLink($email);
        $loginUrl = site_url('auth/magic-link/' . $issued->rawToken);

        // AC1 impose une réponse neutre quelle que soit l'existence du compte ET le sort de
        // l'envoi (NFR5, UX-DR18) ; l'AC d'échec d'envoi est différée (readiness 2026-06-10 #12).
        // EmailService trace déjà l'échec dans email_events (status 'failed') ; on capture le
        // résultat pour un signal ops au niveau contrôleur sans altérer la vue neutre.
        $delivery = (new EmailService())->sendUserLoginEmail($email, $loginUrl);
        if (! $delivery->sent) {
            log_message('warning', 'MagicLink: échec de livraison de l\'email de connexion');
        }

        return view('auth/magic_link_sent', ['email' => $email]);
    }

    /** GET /auth/magic-link/{token} — valide le token et crée la session (Story 1.4) */
    public function consume(string $token): mixed
    {
        // TODO: Story 1.4
        return redirect()->to(site_url('/'));
    }
}
