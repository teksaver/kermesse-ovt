<?php

namespace App\Controllers\Owner;

use App\Controllers\BaseController;
use App\Services\OwnerLoginService;
use App\Services\OwnerSessionService;
use App\Services\SessionOutcome;

/**
 * Handles the owner "me connecter" flow.
 *
 * GET  /owner/login              → show the login form
 * POST /owner/login              → validate email, call OwnerLoginService, show confirmation
 * GET  /owner/login/(:segment)   → consume an owner_login token, create admin session
 */
class LoginController extends BaseController
{
    /**
     * Display the login form.
     */
    public function showLoginForm(): string
    {
        helper('form');

        return view('owner/login', [
            'errors'   => [],
            'oldEmail' => '',
        ]);
    }

    /**
     * Process the email submission and send a login / validation link.
     */
    public function requestLink(): mixed
    {
        helper('form');

        $submittedEmail  = $this->request->getPost('owner_email');
        $normalisedEmail = is_scalar($submittedEmail) ? trim((string) $submittedEmail) : '';

        $rules = [
            'owner_email' => 'required|valid_email|max_length[320]',
        ];

        $messages = [
            'owner_email' => [
                'required'    => 'Veuillez saisir votre adresse email.',
                'valid_email' => 'Veuillez saisir une adresse email valide.',
                'max_length'  => "L'adresse email est trop longue.",
            ],
        ];

        $validation = \Config\Services::validation();
        $validation->setRules($rules, $messages);

        if (! $validation->run(['owner_email' => $normalisedEmail])) {
            return view('owner/login', [
                'errors'   => $validation->getErrors(),
                'oldEmail' => $normalisedEmail,
            ]);
        }

        $service = new OwnerLoginService();
        $service->requestOwnerLink($normalisedEmail);

        // Always show the neutral confirmation — never reveal whether the email exists
        return view('owner/login', [
            'errors'      => [],
            'oldEmail'    => '',
            'showConfirm' => true,
        ]);
    }

    /**
     * Display the token confirmation page (prefetch-safe GET handler).
     *
     * Validates the token before rendering so invalid/expired links are surfaced
     * immediately. If valid, stores the raw token in the server-side session so
     * the confirmation form can POST to a fixed URL without embedding the token
     * in the HTML source.
     */
    public function showLoginConfirm(string $rawToken): mixed
    {
        helper('form');

        $service = new OwnerSessionService();
        $peek    = $service->prevalidateLoginToken($rawToken);

        if (! $peek->isSuccess()) {
            session()->remove(['pending_login_token', 'pending_login_token_id']);

            return view('owner/login_result', [
                'status'   => $peek->status,
                'loginUrl' => site_url('owner/login'),
            ]);
        }

        $session = session();
        $session->regenerate(true);
        $session->remove('pending_login_token');
        $session->set('pending_login_token_id', $peek->tokenId);

        return view('owner/login_confirm', [
            'loginUrl' => site_url('owner/login'),
        ]);
    }

    /**
     * Confirm the login via a session-backed token (POST /owner/login/confirm).
     *
     * The raw token was stored in the session by showLoginConfirm.
     * Clears the session token regardless of outcome.
     */
    public function confirmLogin(): mixed
    {
        $tokenId = session()->get('pending_login_token_id');
        session()->remove(['pending_login_token', 'pending_login_token_id']);

        if ($tokenId === null || ! is_numeric($tokenId)) {
            session()->remove(['owner_id', 'kermesse_id', 'owner_admin_authenticated']);

            return view('owner/login_result', [
                'status'   => SessionOutcome::INVALID_TOKEN,
                'loginUrl' => site_url('owner/login'),
            ]);
        }

        $service = new OwnerSessionService();
        $outcome = $service->consumePrevalidatedLoginToken((int) $tokenId);

        if ($outcome->isSuccess()) {
            $session = session();
            $session->regenerate(true);
            $session->set([
                'owner_id'                  => $outcome->ownerId,
                'kermesse_id'               => $outcome->kermesseId,
                'owner_admin_authenticated' => true,
            ]);

            return redirect()->to(site_url('admin/kermesses/' . $outcome->kermesseId));
        }

        session()->remove(['owner_id', 'kermesse_id', 'owner_admin_authenticated']);

        return view('owner/login_result', [
            'status'   => $outcome->status,
            'loginUrl' => site_url('owner/login'),
        ]);
    }

    /**
     * Consume an owner_login token and create an admin session (POST handler).
     *
     * On success: regenerate session ID, set admin keys, redirect to admin dashboard.
     * On failure: purge any pre-existing admin session, render the login_result view.
     */
    public function consumeLoginToken(string $rawToken): mixed
    {
        $service = new OwnerSessionService();
        $outcome = $service->consumeLoginToken($rawToken);

        if ($outcome->isSuccess()) {
            $session = session();
            $session->regenerate(true);
            $session->set([
                'owner_id'                  => $outcome->ownerId,
                'kermesse_id'               => $outcome->kermesseId,
                'owner_admin_authenticated' => true,
            ]);

            return redirect()->to(site_url('admin/kermesses/' . $outcome->kermesseId));
        }

        // Purge any pre-existing admin session before rendering an error to prevent session leftover.
        session()->remove(['owner_id', 'kermesse_id', 'owner_admin_authenticated']);

        return view('owner/login_result', [
            'status'   => $outcome->status,
            'loginUrl' => site_url('owner/login'),
        ]);
    }
}
