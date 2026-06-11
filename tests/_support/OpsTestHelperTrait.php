<?php

/**
 * Shared helpers for ops endpoint tests (migrate, activate, probe, status).
 *
 * Provides HMAC header construction and Kermesse config setup/teardown so
 * that duplicate boilerplate is eliminated across the 8+ ops test files.
 */
trait OpsTestHelperTrait
{
    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';

    /**
     * Build valid HMAC headers for a given ops route.
     *
     * @param string $routePath The logical route path (e.g. 'ops/migrate', 'ops/probe').
     * @param string $body      Raw request body (default: empty string).
     * @param string|null $nonce Override nonce (default: random).
     *
     * @return array<string, string>
     */
    private function buildOpsHeaders(string $routePath, string $body = '', ?string $nonce = null): array
    {
        $timestamp = (string) time();
        $nonce     = $nonce ?? bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', $routePath, $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        return [
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ];
    }

    /**
     * Configure Kermesse for ops tests (disable production-only gate, set HMAC secret).
     */
    private function setUpOpsConfig(): void
    {
        config('Kermesse')->opsMigrationProductionOnly      = false;
        config('Kermesse')->opsMigrationHmacSecret          = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
    }
}
