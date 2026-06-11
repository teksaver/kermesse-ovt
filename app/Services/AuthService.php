<?php

namespace App\Services;

use App\Models\UserModel;

/**
 * Universal user session management via Magic Link.
 * Owns: user session creation, logout, profile retrieval.
 * Implemented in Stories 1.3–1.4.
 */
class AuthService
{
    public function __construct(
        private readonly UserModel $userModel,
    ) {}

    /**
     * Return the authenticated user from session, or null if not connected.
     *
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $userId = session()->get('user_id');
        if (! is_int($userId) && ! is_string($userId)) {
            return null;
        }

        return $this->userModel->find((int) $userId);
    }

    public function isAuthenticated(): bool
    {
        return $this->currentUser() !== null;
    }
}
