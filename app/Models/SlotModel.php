<?php

namespace App\Models;

use CodeIgniter\Model;

class SlotModel extends Model
{
    protected $table      = 'slots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'stand_id',
        'starts_at',
        'ends_at',
        'capacity',
        'status',
    ];

    /**
     * Return active slots for a list of stand IDs, sorted by stand then start time.
     *
     * @param int[] $standIds
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForStandIds(array $standIds): array
    {
        if (empty($standIds)) {
            return [];
        }

        return $this->whereIn('stand_id', $standIds)
            ->where('status', 'active')
            ->orderBy('stand_id', 'ASC')
            ->orderBy('starts_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}
