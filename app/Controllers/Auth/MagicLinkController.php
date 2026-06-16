<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

use App\Models\UserRoleModel;
use App\Models\UserModel;
use App\Services\EmailService;
use App\Services\ProfileService;
use App\Services\RoleService;
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
    public function verify(string $token): mixed
    {
        $tokenService = new TokenService();
        $result       = $tokenService->validateMagicLink($token);

        if (! $result->isValid()) {
            return $this->response->setStatusCode(400)->setBody(view('auth/magic_link_invalid'));
        }

        $tokenRow = $result->tokenRow;
        $tokenId  = (int) $tokenRow['id'];
        $email    = strtolower(trim((string) $tokenRow['email']));

        $db = \Config\Database::connect();
        $db->transStart();

        // Atomic single-use guard against concurrent claims
        if (! $tokenService->markMagicLinkTokenAsUsed($tokenId)) {
            $db->transRollback();
            return $this->response->setStatusCode(400)->setBody(view('auth/magic_link_invalid'));
        }

        $userId = (new UserModel())->findOrCreateByEmail($email);

        if ($userId === null) {
            $db->transRollback();
            log_message('error', 'MagicLink: findOrCreateByEmail returned null for token ' . $tokenId);
            return $this->response->setStatusCode(400)->setBody(view('auth/magic_link_invalid'));
        }

        $db->transComplete();

        $userModel  = new UserModel();
        $userRecord = $userModel->find($userId);
        if ($userRecord === null) {
            log_message('error', 'MagicLink: user disappeared after successful token claim for user ' . $userId);
            return $this->response->setStatusCode(400)->setBody(view('auth/magic_link_invalid'));
        }

        // Story 5.4: capture first-login status BEFORE updating last_login_at.
        // The confirmation controller sets last_login_at after the user confirms.
        $isFirstLogin = $userRecord['last_login_at'] === null;

        // Mark invitation accepted per-kermesse so the dashboard can distinguish
        // "accepted this kermesse" from "has a global account" (NFR5 privacy).
        $kermesseId = (int) ($tokenRow['kermesse_id'] ?? 0);
        if ($kermesseId > 0) {
            db_connect()
                ->table('kermesse_user_roles')
                ->where('kermesse_id', $kermesseId)
                ->where('user_id', $userId)
                ->where('accepted_at IS NULL', null, false)
                ->update(['accepted_at' => date('Y-m-d H:i:s')]);
        }

        session()->regenerate();
        session()->set([
            'user_id'      => $userId,
            'is_logged_in' => true,
        ]);

        // Story 5.10 (Stateless): first login and returning logins are handled identically,
        // we just record the timestamp and proceed directly. Divergences are gone.
        $profileService = new ProfileService($userModel);
        if (! $profileService->recordReturningLogin($userId)) {
            // Non-critical audit write — log but continue so a transient DB hiccup does not lock the user out.
            log_message('error', 'MagicLink: login timestamp update failed for user ' . $userId);
        }

        if ($kermesseId > 0) {
            (new RoleService(new UserRoleModel(), $userModel))->recordAccess($kermesseId, $userId);
        }

        $url = session('redirect_url');
        session()->remove('redirect_url');

        $fallback = $kermesseId > 0 ? site_url('kermesse/' . $kermesseId) : site_url('/');
        $redirectUrl = $this->localRedirectTarget($url, $fallback);

        return redirect()->to($redirectUrl);
    }
}
