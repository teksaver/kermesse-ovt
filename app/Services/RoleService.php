<?php

namespace App\Services;

use App\Models\UserModel;
use App\Models\UserRoleModel;

/**
 * Per-kermesse role authorization and assignment.
 * Owns: role checks (Owner/Admin/Gestionnaire/Bénévole), role assignment, invitations.
 * Implemented in Stories 2.x and 4.5.
 */
class RoleService
{
    public function __construct(
        private readonly UserRoleModel $userRoleModel,
        private readonly UserModel $userModel,
    ) {}

    /**
     * Return the role string for the given user on the given kermesse, or null.
     */
    public function getRoleForUser(int $kermesseId, int $userId): ?string
    {
        $row = $this->userRoleModel->findByKermesseAndUser($kermesseId, $userId);

        return $row !== null ? (string) $row['role'] : null;
    }

    public function isOwnerOrAdmin(int $kermesseId, int $userId): bool
    {
        $role = $this->getRoleForUser($kermesseId, $userId);

        return in_array($role, [UserRoleModel::ROLE_OWNER, UserRoleModel::ROLE_ADMIN], true);
    }

    public function canManage(int $kermesseId, int $userId): bool
    {
        $role = $this->getRoleForUser($kermesseId, $userId);

        return in_array($role, [
            UserRoleModel::ROLE_OWNER,
            UserRoleModel::ROLE_ADMIN,
            UserRoleModel::ROLE_GESTIONNAIRE,
        ], true);
    }
}
