<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /ops/migrate endpoint.
 *
 * These tests verify the HTTP-level behaviour of the endpoint:
 * response shape, sanitisation, and that no technical details leak.
 *
 * Tests requiring a real MariaDB connection are tagged @group mariadb.
 *
 * @internal
 */
final class OpsMigrateEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';

    protected function setUp(): void
    {
        parent::setUp();

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationHmacSecret = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
    }

    /**
     * Build valid HMAC headers for testing.
     *
     * @return array<string, string>
     */
    private function buildValidHeaders(string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        return [
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ];
    }

    public function testResponseShapeOnRejectedRequest(): void
    {
        $result = $this->post('ops/migrate');

        $result->assertStatus(403);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertArrayHasKey('error', $json);
        $this->assertSame('ops_unauthorized', $json['error']);
    }

    public function testRejectedResponseDoesNotContainSqlOrStackTrace(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'sanitize-test',
            'X-Kermesse-Signature' => 'bad-sig',
        ])->post('ops/migrate');

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('CREATE TABLE', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString($this->testSecret, $body);
    }
}
