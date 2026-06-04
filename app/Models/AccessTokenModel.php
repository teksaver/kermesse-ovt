<?php

namespace App\Models;

use CodeIgniter\Model;

class AccessTokenModel extends Model
{
    protected $table         = 'access_tokens';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'token_hash',
        'token_type',
        'owner_id',
        'kermesse_id',
        'email',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $validationRules = [
        'token_hash' => 'required|max_length[64]',
        'token_type' => 'required|in_list[owner_validation,owner_login,volunteer_management]',
        'expires_at' => 'required',
    ];
}
