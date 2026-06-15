<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProfileDivergenceModel;
use App\Models\UserRoleModel;
use App\Models\UserModel;

/**
 * Owns profile divergence resolution: updates the user record and marks
 * all pending divergences as resolved in a single transaction.
 * Story 3.6.
 */
class ProfileService
{
    public function __construct(
        private readonly UserModel $userModel,
        private readonly ProfileDivergenceModel $profileDivergenceModel,
        private readonly ?UserRoleModel $userRoleModel = null,
    ) {}

    /**
     * Confirm and save the user profile at first login.
     *
     * Called once — when `users.last_login_at IS NULL` — to let the user verify
     * or correct their prénom/nom/téléphone submitted during a public signup.
     * Sets `last_login_at` to mark the first login as completed and resolves
     * any pre-existing divergences for the current kermesse in the same transaction.
     *
     * @param array{first_name: string, last_name: string, phone: string} $profileData
     */
    public function confirmFirstLogin(int $userId, array $profileData, ?int $kermesseId = null): bool
    {
        $db = db_connect();
        if (! $db->transBegin()) {
            return false;
        }

        if ($this->userModel->find($userId) === null) {
            $db->transRollback();
            return false;
        }

        $updated = $this->userModel->update($userId, [
            'first_name'    => $profileData['first_name'],
            'last_name'     => $profileData['last_name'],
            'phone'         => $profileData['phone'],
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        if ($updated === false) {
            $db->transRollback();
            return false;
        }

        // Silently resolve any pre-existing divergences (the user has now confirmed).
        $divergences = $kermesseId !== null
            ? $this->profileDivergenceModel->findUnresolvedByUserAndKermesse($userId, $kermesseId)
            : $this->profileDivergenceModel->findUnresolvedByUser($userId);
        if (! empty($divergences)) {
            $ids = array_column($divergences, 'id');
            $resolved = $this->profileDivergenceModel
                ->whereIn('id', $ids)
                ->update(null, ['resolved_at' => date('Y-m-d H:i:s')]);
            if ($resolved === false) {
                $db->transRollback();
                return false;
            }
        }

        if (! $db->transCommit()) {
            $db->transRollback();
            return false;
        }

        if ($kermesseId !== null) {
            $this->roleService()->recordAccess($kermesseId, $userId);
        }

        return true;
    }

    public function recordReturningLogin(int $userId): bool
    {
        if ($this->userModel->find($userId) === null) {
            return false;
        }

        return $this->userModel->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]) !== false;
    }

    /**
     * Resolve all unresolved profile divergences for a user.
     *
     * $choice = 'submitted' → update user profile with the most-recent divergence values.
     * $choice = 'keep'      → leave user profile unchanged.
     * In both cases, every unresolved divergence row is stamped resolved_at.
     *
     * Returns true on success, false if the transaction could not commit.
     */
    public function resolveProfileDivergences(int $userId, string $choice, ?int $kermesseId = null): bool
    {
        if ($this->userModel->find($userId) === null) {
            return false;
        }

        $divergences = $kermesseId !== null
            ? $this->profileDivergenceModel->findUnresolvedByUserAndKermesse($userId, $kermesseId)
            : $this->profileDivergenceModel->findUnresolvedByUser($userId);

        if (empty($divergences)) {
            return true;
        }

        $db = db_connect();
        if (! $db->transBegin()) {
            return false;
        }

        if ($choice === 'submitted') {
            // findUnresolvedByUser orders DESC — first row is the most recent.
            $latest  = $divergences[0];
            $updated = $this->userModel->update($userId, [
                'first_name' => $latest['submitted_first_name'],
                'last_name'  => $latest['submitted_last_name'],
                'phone'      => $latest['submitted_phone'],
            ]);
            if ($updated === false) {
                $db->transRollback();
                return false;
            }
        }

        // Use the model to mark divergences resolved so timestamps are managed properly.
        $ids = array_column($divergences, 'id');
        $resolved = $this->profileDivergenceModel
            ->whereIn('id', $ids)
            ->update(null, ['resolved_at' => date('Y-m-d H:i:s')]);
        if ($resolved === false) {
            $db->transRollback();
            return false;
        }

        if (! $db->transCommit()) {
            $db->transRollback();
            return false;
        }

        if ($kermesseId !== null) {
            $this->roleService()->recordAccess($kermesseId, $userId);
        }

        return true;
    }

    /**
     * Update the authenticated user's own profile (name, phone, optionally email).
     *
     * Email change also refreshes email_hash so the lookup index stays consistent.
     * Validation (required fields, email uniqueness) is enforced upstream by the controller.
     */
    public function updateOwnProfile(
        int $userId,
        string $email,
        string $firstName,
        string $lastName,
        string $phone,
    ): bool {
        $current = $this->userModel->find($userId);
        if ($current === null) {
            return false;
        }

        $data = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
        ];

        $email = strtolower($email);
        if ($email !== strtolower((string) $current['email'])) {
            $data['email']      = $email;
            $data['email_hash'] = $this->userModel->hashEmail($email);
        }

        return $this->userModel->update($userId, $data) !== false;
    }

    private function roleService(): RoleService
    {
        return new RoleService($this->userRoleModel ?? new UserRoleModel(), $this->userModel);
    }
}
