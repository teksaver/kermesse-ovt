<?php

namespace App\Controllers\Ops;

use CodeIgniter\RESTful\ResourceController;

class MigrationController extends ResourceController
{
    public function migrate()
    {
        // Require HMAC signature, timestamp, nonce, DB lock
        $secret = getenv('OPS_HMAC_SECRET');
        if (empty($secret)) {
            return $this->failServerError('Missing HMAC secret');
        }

        // Simplistic stub for Story 1.1 Greenfield
        return $this->respond(['status' => 'success', 'message' => 'Migration runner initialized']);
    }

    public function status()
    {
        return $this->respond(['status' => 'idle']);
    }
}
