<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailEventModel extends Model
{
    protected $table         = 'email_events';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'event_type',
        'status',
        'recipient_email',
        'recipient_hash',
        'error_message',
        'metadata',
    ];

    protected $validationRules = [
        'event_type'       => 'required|max_length[50]',
        'status'           => 'required|in_list[queued,sent,failed]',
        'recipient_email'  => 'required|valid_email|max_length[320]',
        'recipient_hash'   => 'required|max_length[64]',
    ];
}
