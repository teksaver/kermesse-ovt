<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\ProfileDivergenceModel;
use App\Models\UserModel;
use App\Services\ProfileService;

/**
 * Profile confirmation and divergence resolution at login.
 * Story 3.6: divergence resolution (keep / submitted).
 * Story 5.4: first-login confirmation screen (always shown on first access).
 */
class ProfileResolutionController extends BaseController
{
    /** GET /auth/profile-resolution */
    public function show(): mixed
    {
        $userId = (int) session()->get('user_id');

        $userModel  = new UserModel();
        $storedUser = $userModel->find($userId);

        if ($storedUser === null) {
            session()->remove(['is_logged_in', 'user_id']);
            session()->destroy();
            return redirect()->to(site_url('auth/login'));
        }

        // Story 5.4 — AC1: first-login confirmation (always shown, regardless of divergences).
        if (session()->get('pending_first_login_confirmation') === true) {
            return view('auth/profile_resolution', [
                'title'   => 'Bienvenue — confirmez vos coordonnées',
                'mode'    => 'first_login',
                'user'    => [
                    'first_name' => $storedUser['first_name'],
                    'last_name'  => $storedUser['last_name'],
                    'email'      => $storedUser['email'],
                    'phone'      => $storedUser['phone'],
                ],
                'old'     => session()->getFlashdata('old') ?? [],
                'errors'  => session()->getFlashdata('errors') ?? [],
            ]);
        }

        // Story 3.6 — AC2: divergence resolution for returning users.
        $kermesseId      = $this->pendingKermesseId();
        $divergenceModel = new ProfileDivergenceModel();
        $divergences     = $kermesseId !== null
            ? $divergenceModel->findUnresolvedByUserAndKermesse($userId, $kermesseId)
            : $divergenceModel->findUnresolvedByUser($userId);

        if (empty($divergences)) {
            session()->remove('pending_profile_resolution');
            session()->remove('pending_resolution_kermesse_id');
            return redirect()->to(site_url('/'));
        }

        // Pass only the fields needed by the view — never expose full user entity (PII).
        // findUnresolvedByUser orders DESC — first row is the most recent submitted values.
        return view('auth/profile_resolution', [
            'title'      => 'Vérification de votre profil',
            'mode'       => 'divergence',
            'storedUser' => [
                'first_name' => $storedUser['first_name'],
                'last_name'  => $storedUser['last_name'],
                'phone'      => $storedUser['phone'],
            ],
            'divergence' => $divergences[0],
        ]);
    }

    /** POST /auth/profile-resolution */
    public function resolve(): mixed
    {
        $userId = (int) session()->get('user_id');
        $userModel = new UserModel();

        if ($userId <= 0 || $userModel->find($userId) === null) {
            session()->remove(['is_logged_in', 'user_id']);
            session()->destroy();
            return redirect()->to(site_url('auth/login'));
        }

        // Story 5.4 — AC1: first-login confirmation.
        if (session()->get('pending_first_login_confirmation') === true) {
            return $this->handleFirstLoginConfirmation($userId);
        }

        // Story 3.6 — AC2: divergence resolution.
        if (session()->get('pending_profile_resolution') === true) {
            return $this->handleDivergenceResolution($userId);
        }

        return redirect()->to(site_url('/'));
    }

    private function handleFirstLoginConfirmation(int $userId): mixed
    {
        $validation = service('validation');
        $rules = [
            'first_name' => 'required|max_length[100]',
            'last_name'  => 'required|max_length[100]',
            'phone'      => 'permit_empty|max_length[20]',
        ];

        $post = [
            'first_name' => $this->scalarPost('first_name'),
            'last_name'  => $this->scalarPost('last_name'),
            'phone'      => $this->scalarPost('phone'),
        ];

        if (! $validation->setRules($rules)->run($post)) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('old', $post)
                ->with('errors', $validation->getErrors());
        }

        $service = new ProfileService(new UserModel(), new ProfileDivergenceModel());
        $success = $service->confirmFirstLogin($userId, $post, $this->pendingKermesseId());

        if (! $success) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('errors', ['global' => 'Une erreur est survenue. Veuillez réessayer.']);
        }

        // Capture kermesse intent BEFORE clearing session so the redirect fallback can use it.
        $pendingKermesseId = (int) session()->get('pending_resolution_kermesse_id');

        session()->remove('pending_first_login_confirmation');
        session()->remove('pending_resolution_kermesse_id');

        $url = session('redirect_url');
        session()->remove('redirect_url');

        $fallback       = $pendingKermesseId > 0 ? site_url('kermesse/' . $pendingKermesseId) : null;
        $redirectTarget = $this->localRedirectTarget($url, $fallback);

        return redirect()->to($redirectTarget);
    }

    private function handleDivergenceResolution(int $userId): mixed
    {
        $choice = $this->request->getPost('choice');

        if (! in_array($choice, ['keep', 'submitted'], true)) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('error', 'Veuillez choisir une option avant de continuer.');
        }

        $service = new ProfileService(new UserModel(), new ProfileDivergenceModel());
        $success = $service->resolveProfileDivergences($userId, (string) $choice, $this->pendingKermesseId());

        if (! $success) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('error', 'Une erreur est survenue. Veuillez réessayer.');
        }

        session()->remove('pending_profile_resolution');
        session()->remove('pending_resolution_kermesse_id');

        // Honour a redirect intent that was stored before the magic link login.
        $url = session('redirect_url');
        session()->remove('redirect_url');

        $redirectTarget = $this->localRedirectTarget($url);

        return redirect()->to($redirectTarget);
    }

    private function scalarPost(string $key): string
    {
        $value = $this->request->getPost($key);

        return trim(is_array($value) ? '' : (string) $value);
    }

    private function pendingKermesseId(): ?int
    {
        $kermesseId = (int) session()->get('pending_resolution_kermesse_id');

        return $kermesseId > 0 ? $kermesseId : null;
    }
}
