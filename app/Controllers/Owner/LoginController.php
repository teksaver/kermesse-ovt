<?php

namespace App\Controllers\Owner;

use App\Controllers\BaseController;
use App\Services\OwnerLoginService;

/**
 * Handles the owner "me connecter" flow for owners whose validation link has expired.
 *
 * GET  /owner/login  → show the login form
 * POST /owner/login  → validate email, call OwnerLoginService, show confirmation
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
     * Process the email submission and send a new validation link.
     */
    public function requestLink(): mixed
    {
        helper('form');

        $submittedEmail = $this->request->getPost('owner_email');
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
}
