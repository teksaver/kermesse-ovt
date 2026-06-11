<?php

namespace App\Controllers\Ops;

use CodeIgniter\RESTful\ResourceController;

class MigrationController extends ResourceController
{
    public function migrate()
    {
        $secret = getenv('OPS_HMAC_SECRET');
        if (empty($secret)) {
            return $this->failServerError('Missing HMAC secret');
        }

        $hmac = $this->request->getHeaderLine('X-Ops-Signature');
        $timestamp = $this->request->getHeaderLine('X-Ops-Timestamp');
        $nonce = $this->request->getHeaderLine('X-Ops-Nonce');

        if (!$hmac || !$timestamp || !$nonce) {
            return $this->failUnauthorized('Missing security headers');
        }

        if (time() - $timestamp > 300) {
            return $this->failUnauthorized('Timestamp expired');
        }

        $payload = $timestamp . '.' . $nonce;
        $expectedHmac = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedHmac, $hmac)) {
            return $this->failUnauthorized('Invalid HMAC');
        }
        
        $db = \Config\Database::connect();
        try {
            $db->query("INSERT INTO ops_nonces (nonce_hash, expires_at) VALUES (?, ?)", [hash('sha256', $nonce), date('Y-m-d H:i:s', time() + 600)]);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            return $this->failUnauthorized('Replayed nonce');
        }

        // Simplistic stub for Story 1.1 Greenfield
        return $this->respond(['status' => 'success', 'message' => 'Migration runner initialized']);
    }

    public function status()
    {
        return $this->respond(['status' => 'idle']);
    }
}
