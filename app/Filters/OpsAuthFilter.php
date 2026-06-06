<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Kermesse;

/**
 * Authenticates ops endpoint requests using HMAC-SHA256.
 *
 * Expected headers:
 *   X-Kermesse-Timestamp — Unix timestamp (seconds)
 *   X-Kermesse-Nonce     — Opaque single-use value
 *   X-Kermesse-Signature — HMAC-SHA256 hex signature
 *
 * Signed payload: timestamp\nnonce\nmethod\nroutePath\nsha256(rawBody)
 */
class OpsAuthFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var \CodeIgniter\HTTP\IncomingRequest $request */
        $config = config(Kermesse::class);

        // POST only
        if (strtolower($request->getMethod()) !== 'post') {
            return $this->denyRequest();
        }

        // Production-only gate
        if ($config->opsMigrationProductionOnly && ENVIRONMENT !== 'production') {
            return $this->denyRequest();
        }

        // Secret must be configured
        if ($config->opsMigrationHmacSecret === '') {
            log_message('error', 'OpsAuthFilter: HMAC secret is not configured.');
            return $this->denyRequest();
        }

        $timestamp = $request->getHeaderLine('X-Kermesse-Timestamp');
        $nonce     = $request->getHeaderLine('X-Kermesse-Nonce');
        $signature = $request->getHeaderLine('X-Kermesse-Signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return $this->denyRequest();
        }

        // Timestamp freshness
        if (!$this->isTimestampValid((int) $timestamp, $config->opsMigrationAllowedTimestampSkew)) {
            return $this->denyRequest();
        }

        // HMAC verification before any nonce write.
        if (!$this->isSignatureValid($request, $timestamp, $nonce, $signature, $config->opsMigrationHmacSecret)) {
            return $this->denyRequest();
        }

        // Nonce anti-replay.
        if (!$this->consumeNonce($nonce, $config->opsMigrationNonceTTL)) {
            return $this->denyRequest();
        }

        return null; // Allow request through
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        // No post-processing needed.
    }

    /**
     * Validate that the timestamp is within the allowed skew window.
     */
    private function isTimestampValid(int $timestamp, int $allowedSkew): bool
    {
        $now = time();

        return abs($now - $timestamp) <= $allowedSkew;
    }

    /**
     * Check nonce uniqueness and store its hash for anti-replay.
     *
     * Returns true if the nonce is fresh (first use), false if replayed or on error.
     */
    private function consumeNonce(string $nonce, int $nonceTTL): bool
    {
        $nonceHash = hash('sha256', $nonce);
        $expiresAt = date('Y-m-d H:i:s', time() + $nonceTTL);

        $db = db_connect();

        try {
            $this->bootstrapNonceTable($db);

            // Purge expired nonces opportunistically.
            $db->query(
                'DELETE FROM `ops_nonces` WHERE `expires_at` < NOW()'
            );

            // Attempt to insert — duplicate hash means replay.
            $db->query(
                'INSERT INTO `ops_nonces` (`nonce_hash`, `expires_at`) VALUES (?, ?)',
                [$nonceHash, $expiresAt]
            );
        } catch (\Throwable $e) {
            // Duplicate entry or DB error → reject
            log_message('error', 'OpsAuthFilter: nonce insert failed.');
            return false;
        }

        return $db->affectedRows() === 1;
    }

    /**
     * Ensure nonce storage exists before the first migration call on a blank DB.
     */
    private function bootstrapNonceTable(\CodeIgniter\Database\BaseConnection $db): void
    {
        $db->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `ops_nonces` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nonce_hash`   VARCHAR(64)     NOT NULL,
                `expires_at`   DATETIME        NOT NULL,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ops_nonces_hash` (`nonce_hash`),
                KEY `idx_ops_nonces_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            SQL);
    }

    /**
     * Verify the HMAC-SHA256 signature against the expected payload.
     *
     * Payload: timestamp\nnonce\nmethod\nroutePath\nsha256(rawBody)
     */
    private function isSignatureValid(
        RequestInterface $request,
        string $timestamp,
        string $nonce,
        string $signature,
        string $secret
    ): bool {
        /** @var \CodeIgniter\HTTP\IncomingRequest $request */
        $method = strtoupper($request->getMethod());
        // Bind the signature to the route actually being called. The path is
        // normalised without a leading slash or base URL so a message signed
        // for one ops route cannot be replayed against another (cross-route replay).
        // A leading 'index.php/' front-controller segment is stripped so the signed
        // path stays the logical route ('ops/migrate') no matter how the front
        // controller exposes the URI — otherwise historical signatures would break.
        $routePath = trim($request->getPath(), '/');
        
        // Ensure index.php prefix and any following slashes are robustly stripped
        // in case the request is routed through index.php directly.
        if (stripos($routePath, 'index.php') === 0) {
            $routePath = ltrim(substr($routePath, 9), '/');
        }
        $bodyHash = hash('sha256', (string) $request->getBody());

        $payload = implode("\n", [$timestamp, $nonce, $method, $routePath, $bodyHash]);

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Return a generic 403 JSON response — never reveal HMAC details.
     */
    private function denyRequest(): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(403);
        $response->setJSON(['error' => 'ops_unauthorized']);
        $response->setHeader('Content-Type', 'application/json');

        return $response;
    }
}
