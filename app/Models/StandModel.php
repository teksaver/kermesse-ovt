<?php

namespace App\Models;

use CodeIgniter\Model;

class StandModel extends Model
{
    protected $table      = 'stands';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'kermesse_id',
        'name',
        'display_order',
        'status',
    ];

    /**
     * Return active stands for a kermesse, ordered for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForKermesse(int $kermesseId): array
    {
        return $this->where('kermesse_id', $kermesseId)
            ->where('status', 'active')
            ->orderBy('display_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Next display_order value for a kermesse.
     */
    public function nextDisplayOrder(int $kermesseId): int
    {
        $result = $this->selectMax('display_order')
            ->where('kermesse_id', $kermesseId)
            ->where('status', 'active')
            ->first();

        return (int) ($result['display_order'] ?? 0) + 1;
    }

    /**
     * Case-insensitive duplicate name check (active stands only).
     */
    public function hasActiveDuplicate(int $kermesseId, string $name, ?int $excludeId = null): bool
    {
        $builder = $this->where('kermesse_id', $kermesseId)
            ->where('status', 'active')
            ->where('LOWER(name)', mb_strtolower(trim($name)));

        if ($excludeId !== null) {
            $builder = $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }
}
