<?php

namespace App\Models;

use CodeIgniter\Model;

class KermesseModel extends Model
{
    protected $table         = 'kermesses';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'owner_id',
        'public_slug',
        'name',
        'event_date',
        'location',
        'short_description',
        'timezone',
        'status',
    ];

    protected $validationRules = [
        'owner_id'          => 'required|is_natural_no_zero',
        'public_slug'       => 'required|max_length[255]',
        'name'              => 'required|max_length[255]',
        'location'          => 'required|max_length[255]',
        'short_description' => 'max_length[500]',
        'status'            => 'required|in_list[preparation,open,closed]',
    ];
}
