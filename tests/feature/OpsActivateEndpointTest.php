<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /ops/activate endpoint.
 *
 * These tests cover DB-free auth rejection and sanitisation paths that fail
 * before OpsAuthFilter writes the nonce. Valid HMAC activation paths live in
 * OpsActivateEndpointMariaDBTest with @group mariadb.
 *
 * @internal
 */
final class OpsActivateEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';
    private \Config\Kermesse $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Kermesse');
        $this->originalConfig = clone $config;

        $config->opsMigrationProductionOnly = false;
        $config->opsMigrationHmacSecret     = $this->testSecret;
        $config->opsMigrationAllowedTimestampSkew = 300;
    }

    protected function tearDown(): void
    {
        $config = config('Kermesse');
        foreach (get_object_vars($this->originalConfig) as $key => $value) {
            $config->$key = $value;
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Auth — requête sans signature → 403
    // -----------------------------------------------------------------------

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $result = $this->post('ops/activate', ['archive' => 'kermesse-deploy.tar.gz']);

        $result->assertStatus(403);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('ops_unauthorized', $json['error']);
    }

    // -----------------------------------------------------------------------
    // Auth — signature pour ops/migrate rejouée sur ops/activate → 403
    // -----------------------------------------------------------------------

    public function testCrossRouteReplayIsRejected(): void
    {
        $body      = json_encode(['archive' => 'kermesse-deploy.tar.gz']);
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->withBody($body)
            ->post('ops/activate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Sanitisation — la réponse de rejet ne contient pas de secrets ou SQL
    // -----------------------------------------------------------------------

    public function testRejectedResponseDoesNotLeakSensitiveData(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'sanitize-test',
            'X-Kermesse-Signature' => 'bad-sig',
        ])->post('ops/activate', ['archive' => 'kermesse-deploy.tar.gz']);

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('CREATE TABLE', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString($this->testSecret, $body);
    }
}
