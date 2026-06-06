<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * DB-free feature tests for POST /ops/probe.
 *
 * These cover the HMAC rejections that fail BEFORE the nonce is written, so they
 * never touch MariaDB. The enabled/disabled happy paths cross a valid HMAC (and
 * therefore consume a nonce in the DB), so they live in
 * OpsProbeEndpointMariaDBTest with @group mariadb.
 *
 * @internal
 */
final class OpsProbeEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';

    protected function setUp(): void
    {
        parent::setUp();

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationHmacSecret     = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
        // Probe enabled so any rejection is proven to come from the HMAC filter,
        // not from the feature gate.
        config('Kermesse')->opsProbeEnabled = true;
    }

    public function testMissingHmacIsRejectedAsUnauthorized(): void
    {
        $result = $this->post('ops/probe');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    /**
     * A message signed for ops/migrate replayed on ops/probe must be rejected.
     * The derived routePath is ops/probe, so the signature cannot match. A fresh
     * timestamp and unique nonce isolate the cause to the signature, and the
     * rejection happens before any nonce write (DB-free).
     */
    public function testMigrateSignatureReplayedOnProbeIsRejected(): void
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', '');
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/probe');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    /**
     * GET must not reach the probe controller: only a POST route is declared and
     * auto-routing is off, so CodeIgniter has no matching route and refuses it.
     */
    public function testGetProbeIsNotRouted(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->get('ops/probe');
    }
}
