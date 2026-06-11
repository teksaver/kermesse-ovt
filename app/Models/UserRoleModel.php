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
            ->select('k.id, k.name, k.public_slug, k.event_date, k.status, kur.role')
            ->join('kermesses k', 'k.id = kur.kermesse_id')
            ->where('kur.user_id', $userId)
            ->orderBy('k.name', 'ASC')
            ->get()
            ->getResultArray();
    }
}
