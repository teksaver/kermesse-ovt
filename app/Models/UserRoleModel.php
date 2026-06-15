<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Per-kermesse role assignment: one row per (kermesse_id, user_id) pair.
 * Roles: owner, admin, gestionnaire, benevole.
 */
class UserRoleModel extends Model
{
    protected $table         = 'kermesse_user_roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    public const ROLE_OWNER        = 'owner';
    public const ROLE_ADMIN        = 'admin';
    public const ROLE_GESTIONNAIRE = 'gestionnaire';
    public const ROLE_BENEVOLE     = 'benevole';

    protected $allowedFields = [
        'kermesse_id',
        'user_id',
        'role',
        'invited_by',
        'invited_at',
        'accepted_at',
        'first_access_at',
        'last_access_at',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function findByKermesseAndUser(int $kermesseId, int $userId): ?array
    {
        return $this->where('kermesse_id', $kermesseId)->where('user_id', $userId)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByKermesse(int $kermesseId): array
    {
        return $this->where('kermesse_id', $kermesseId)->findAll();
    }

    /**
     * Returns all kermesses for a given user, each row including the user's role.
     *
     * @return list<array<string, mixed>>
     */
    public function findKermessesForUser(int $userId): array
    {
        return $this->db
            ->table('kermesse_user_roles kur')
            ->select('k.id, k.name, k.public_slug, k.event_date, k.location, k.status, kur.role')
            ->join('kermesses k', 'k.id = kur.kermesse_id')
            ->where('kur.user_id', $userId)
            ->orderBy('k.name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Returns the organization team members for a given kermesse.
     * Includes owner, admin, and gestionnaire. Excludes benevole.
     *
     * @return list<array<string, mixed>>
     */
    public function findTeamMembers(int $kermesseId): array
    {
        return $this->db
            ->table('kermesse_user_roles kur')
            ->select('kur.role, kur.invited_at, kur.accepted_at, kur.first_access_at, u.id as user_id, u.email, u.first_name, u.last_name')
            ->join('users u', 'u.id = kur.user_id')
            ->where('kur.kermesse_id', $kermesseId)
            ->whereIn('kur.role', [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_GESTIONNAIRE])
            ->orderBy('kur.role', 'ASC')
            ->orderBy('u.last_name', 'ASC')
            ->get()
            ->getResultArray();
    }
}
