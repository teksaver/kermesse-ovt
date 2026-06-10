<?php

namespace App\Models;

use CodeIgniter\Model;

class VolunteerModel extends Model
{
    protected $table      = 'volunteers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'kermesse_id',
        'first_name',
        'last_name',
        'email',
        'phone',
    ];

    /**
     * Find a volunteer by kermesse and normalized (lowercased, trimmed) email.
     *
     * @return array<string, mixed>|null
     */
    public function findByKermesseAndEmail(int $kermesseId, string $normalizedEmail): ?array
    {
        return $this->where('kermesse_id', $kermesseId)
            ->where('email', $normalizedEmail)
            ->first();
    }

    /**
     * Lock the volunteer row to serialize concurrent overlap checks.
     */
    public function lockForOverlapCheck(int $volunteerId, \CodeIgniter\Database\ConnectionInterface $db): void
    {
        $table = $db->prefixTable('volunteers');
        $lock  = (property_exists($db, 'DBDriver') && $db->DBDriver === 'MySQLi') ? ' FOR UPDATE' : '';
        $db->query("SELECT id FROM {$table} WHERE id = ?{$lock}", [$volunteerId]);
    }
}
