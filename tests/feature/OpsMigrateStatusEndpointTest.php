<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /ops/migrate/status endpoint.
 *
 * These tests cover DB-free authentication rejections that fail before
 * OpsAuthFilter writes the nonce. Valid HMAC status paths live in
 * OpsMigrateStatusEndpointMariaDBTest with @group mariadb.
 *
 * @internal
 */
final class OpsMigrateStatusEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';

    private bool $originalOpsMigrationProductionOnly;
    private string $originalOpsMigrationHmacSecret;
    private int $originalOpsMigrationAllowedTimestampSkew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalOpsMigrationProductionOnly       = config('Kermesse')->opsMigrationProductionOnly;
        $this->originalOpsMigrationHmacSecret           = config('Kermesse')->opsMigrationHmacSecret;
        $this->originalOpsMigrationAllowedTimestampSkew = config('Kermesse')->opsMigrationAllowedTimestampSkew;

        config('Kermesse')->opsMigrationProductionOnly        = false;
        config('Kermesse')->opsMigrationHmacSecret            = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew  = 300;
    }

    protected function tearDown(): void
    {
        config('Kermesse')->opsMigrationProductionOnly       = $this->originalOpsMigrationProductionOnly;
        config('Kermesse')->opsMigrationHmacSecret           = $this->originalOpsMigrationHmacSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = $this->originalOpsMigrationAllowedTimestampSkew;

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Auth — requête sans signature → 403
    // -----------------------------------------------------------------------

    public function testRequestWithoutHmacIsRejected(): void
    {
        $result = $this->post('ops/migrate/status');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Auth — signature invalide → 403
    // -----------------------------------------------------------------------

    public function testRequestWithBadSignatureIsRejected(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => bin2hex(random_bytes(16)),
            'X-Kermesse-Signature' => 'bad-signature',
        ])->post('ops/migrate/status');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Auth — signature valide pour ops/migrate rejouée sur ops/migrate/status → 403
    // -----------------------------------------------------------------------

    public function testCrossRouteReplayIsRejected(): void
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
        ])->post('ops/migrate/status');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Sanitisation — la réponse de rejet ne logue pas de données sensibles
    // -----------------------------------------------------------------------

    public function testRejectedResponseDoesNotContainSensitiveData(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'sanitize-test',
            'X-Kermesse-Signature' => 'bad-sig',
        ])->post('ops/migrate/status');

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString($this->testSecret, $body);
    }
}
