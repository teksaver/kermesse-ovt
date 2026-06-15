<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ProfileDivergenceModel;
use App\Models\UserModel;
use App\Services\ProfileService;

class ProfileController extends BaseController
{
    /** GET /profile */
    public function edit(): mixed
    {
        $userId = (int) session()->get('user_id');
        $user   = model(UserModel::class)->find($userId);

        if ($user === null) {
            return redirect()->to(site_url('auth/login'));
        }

        return view('profile/edit', [
            'title' => 'Mon profil',
            'user'  => $user,
        ]);
    }

    /** POST /profile */
    public function update(): mixed
    {
        $userId = (int) session()->get('user_id');
        $user   = model(UserModel::class)->find($userId);

        if ($user === null) {
            return redirect()->to(site_url('auth/login'));
        }

        $emailInput     = $this->request->getPost('email');
        $firstNameInput = $this->request->getPost('first_name');
        $lastNameInput  = $this->request->getPost('last_name');
        $phoneInput     = $this->request->getPost('phone');

        $email     = trim(is_array($emailInput) ? '' : (string) $emailInput);
        $firstName = trim(is_array($firstNameInput) ? '' : (string) $firstNameInput);
        $lastName  = trim(is_array($lastNameInput) ? '' : (string) $lastNameInput);
        $phone     = trim(is_array($phoneInput) ? '' : (string) $phoneInput);

        $validation = service('validation');
        $isValid    = $validation->setRules(
            [
                'email'      => "required|valid_email|max_length[255]|is_unique[users.email,id,{$userId}]",
                'first_name' => 'required|string|max_length[100]',
                'last_name'  => 'required|string|max_length[100]',
                'phone'      => 'permit_empty|string|max_length[20]',
            ],
            [
                'email' => ['is_unique' => 'Cet email est déjà utilisé.'],
            ]
        )->run([
            'email'      => $email,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
        ]);

        if (! $isValid) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $validation->getErrors());
        }

        $profileService = new ProfileService(
            model(UserModel::class),
            model(ProfileDivergenceModel::class),
        );

        if (! $profileService->updateOwnProfile($userId, $email, $firstName, $lastName, $phone)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Une erreur est survenue lors de la mise à jour de votre profil.');
        }

        session()->setFlashdata('success', 'Votre profil a été mis à jour avec succès.');

        return redirect()->to(site_url('profile'));
    }
}
