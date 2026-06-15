<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Tracks profile field divergences when a public signup uses different
 * first_name / last_name / phone than the user's stored profile.
 * Resolved during the connected profile resolution flow (Story 3.6).
 */
class ProfileDivergenceModel extends Model
{
    protected $table         = 'profile_divergences';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'user_id',
        'kermesse_id',
        'signup_id',
        'submitted_first_name',
        'submitted_last_name',
        'submitted_phone',
        'resolved_at',
    ];

    /**
     * Returns unresolved divergences for a user, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function findUnresolvedByUser(int $userId): array
    {
        return $this->where('user_id', $userId)
            ->where('resolved_at IS NULL', null, false)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    /**
     * Returns unresolved divergences for one user within one kermesse.
     *
     * @return list<array<string, mixed>>
     */
    public function findUnresolvedByUserAndKermesse(int $userId, int $kermesseId): array
    {
        return $this->where('user_id', $userId)
            ->where('kermesse_id', $kermesseId)
            ->where('resolved_at IS NULL', null, false)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }
}
