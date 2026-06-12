<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\ProfileDivergenceModel;
use App\Models\UserModel;
use App\Services\ProfileService;

/**
 * Profile divergence resolution at login.
 * Implemented in Story 3.6.
 */
class ProfileResolutionController extends BaseController
{
    /** GET /auth/profile-resolution */
    public function show(): mixed
    {
        $userId = (int) session()->get('user_id');

        $divergences = (new ProfileDivergenceModel())->findUnresolvedByUser($userId);

        if (empty($divergences)) {
            session()->remove('pending_profile_resolution');
            return redirect()->to(site_url('/'));
        }

        $storedUser = (new UserModel())->find($userId);

        if ($storedUser === null) {
            session()->destroy();
            return redirect()->to(site_url('auth/login'));
        }

        // Pass only the fields needed by the view — never expose full user entity (PII).
        // findUnresolvedByUser orders DESC — first row is the most recent submitted values.
        return view('auth/profile_resolution', [
            'title'      => 'Vérification de votre profil',
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

        // Honour a redirect intent that was stored before the magic link login
        // (e.g. the user was trying to reach a specific page that requires auth).
        $url = session('redirect_url');
        session()->remove('redirect_url');

        $redirectTarget = site_url('/');
        if ($url && (str_starts_with($url, '/') || str_starts_with($url, site_url()))) {
            $redirectTarget = $url;
        }

        return redirect()->to($redirectTarget);
    }
}
