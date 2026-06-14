<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\UserModel;

class ProfileController extends BaseController
{
    /** GET /profile */
    public function edit(): mixed
    {
        $userId = (int) session()->get('user_id');
        $userModel = model(UserModel::class);
        $user = $userModel->find($userId);

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
        $userModel = model(UserModel::class);
        $user = $userModel->find($userId);

        if ($user === null) {
            return redirect()->to(site_url('auth/login'));
        }

        $emailInput = $this->request->getPost('email');
        $email      = trim(is_array($emailInput) ? '' : (string) $emailInput);
        $firstNameInput = $this->request->getPost('first_name');
        $firstName  = trim(is_array($firstNameInput) ? '' : (string) $firstNameInput);
        $lastNameInput = $this->request->getPost('last_name');
        $lastName   = trim(is_array($lastNameInput) ? '' : (string) $lastNameInput);
        $phoneInput = $this->request->getPost('phone');
        $phone      = trim(is_array($phoneInput) ? '' : (string) $phoneInput);

        $validation = service('validation');
        $isValid    = $validation->setRules([
            'email'      => "required|valid_email|is_unique[users.email,id,{$userId}]",
            'first_name' => 'required|string|max_length[100]',
            'last_name'  => 'required|string|max_length[100]',
            'phone'      => 'permit_empty|string|max_length[20]',
        ])->run([
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

        $data = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
        ];

        // If email changed, we must update both email and email_hash
        $email = strtolower($email);
        if ($email !== strtolower((string) $user['email'])) {
            $data['email'] = $email;
            $data['email_hash'] = $userModel->hashEmail($email);
        }

        if (! $userModel->update($userId, $data)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Une erreur est survenue lors de la mise à jour de votre profil.');
        }

        session()->setFlashdata('success', 'Votre profil a été mis à jour avec succès.');

        return redirect()->to(site_url('profile'));
    }
}
