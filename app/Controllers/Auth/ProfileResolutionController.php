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
        $divergences = (new ProfileDivergenceModel())->findUnresolvedByUser($userId);

        if (empty($divergences)) {
            session()->remove('pending_profile_resolution');
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

        // Story 5.4 — AC1: first-login confirmation.
        if (session()->get('pending_first_login_confirmation') === true) {
            return $this->handleFirstLoginConfirmation($userId);
        }

        // Story 3.6 — AC2: divergence resolution.
        return $this->handleDivergenceResolution($userId);
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
            'first_name' => trim((string) $this->request->getPost('first_name')),
            'last_name'  => trim((string) $this->request->getPost('last_name')),
            'phone'      => trim((string) $this->request->getPost('phone')),
        ];

        if (! $validation->setRules($rules)->run($post)) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('old', $post)
                ->with('errors', $validation->getErrors());
        }

        $service = new ProfileService(new UserModel(), new ProfileDivergenceModel());
        $success = $service->confirmFirstLogin($userId, $post);

        if (! $success) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('errors', ['global' => 'Une erreur est survenue. Veuillez réessayer.']);
        }

        session()->remove('pending_first_login_confirmation');

        $url = session('redirect_url');
        session()->remove('redirect_url');

        $redirectTarget = site_url('/');
        if ($url && (str_starts_with($url, '/') || str_starts_with($url, site_url()))) {
            $redirectTarget = $url;
        }

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
        $success = $service->resolveProfileDivergences($userId, (string) $choice);

        if (! $success) {
            return redirect()->to(site_url('auth/profile-resolution'))
                ->with('error', 'Une erreur est survenue. Veuillez réessayer.');
        }

        session()->remove('pending_profile_resolution');

        // Honour a redirect intent that was stored before the magic link login.
        $url = session('redirect_url');
        session()->remove('redirect_url');

        $redirectTarget = site_url('/');
        if ($url && (str_starts_with($url, '/') || str_starts_with($url, site_url()))) {
            $redirectTarget = $url;
        }

        return redirect()->to($redirectTarget);
    }
}
