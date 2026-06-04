<?php

namespace App\Models;

use CodeIgniter\Model;

class OwnerModel extends Model
{
    protected $table         = 'owners';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'email',
        'email_hash',
        'display_name',
        'status',
        'email_verified_at',
    ];

    protected $validationRules = [
        'email'        => 'required|valid_email|max_length[320]',
        'email_hash'   => 'required|max_length[64]',
        'display_name' => 'required|max_length[255]',
        'status'       => 'required|in_list[owner_pending,active]',
    ];
}
