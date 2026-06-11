<?php

namespace App\Models;

use CodeIgniter\Model;

class KermesseModel extends Model
{
    public const STATUS_PREPARATION = 'preparation';
    public const STATUS_OPEN        = 'open';
    public const STATUS_CLOSED      = 'closed';

    protected $table         = 'kermesses';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'created_by',
        'public_slug',
        'name',
        'event_date',
        'location',
        'short_description',
        'timezone',
        'status',
    ];

    protected $validationRules = [
        'created_by'        => 'required|is_natural_no_zero',
        'public_slug'       => 'required|max_length[255]',
        'name'              => 'required|max_length[255]',
        'location'          => 'required|max_length[255]',
        'short_description' => 'max_length[500]',
        'status'            => 'required|in_list[preparation,open,closed]',
    ];
}
