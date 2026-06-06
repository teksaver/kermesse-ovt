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

    /**
     * R-7: the route path the filter signs against is derived from the request
     * URI, which the front controller may expose as 'index.php/ops/migrate'.
     * After the filter's normalisation, a signature built the historical way
     * (routePath = 'ops/migrate') must still validate against the real harness
     * request — otherwise every historical ops/migrate signature breaks silently.
     */
    public function testHarnessMigrateRequestPassesSignatureStage(): void
    {
        // Drive a request so service('request') is the one the filter would see.
        $this->post('ops/migrate');
        $request = service('request');

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', (string) $request->getBody());
        $payload   = implode("\n", [$timestamp, $nonce, strtoupper($request->getMethod()), 'ops/migrate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $method = new \ReflectionMethod(\App\Filters\OpsAuthFilter::class, 'isSignatureValid');
        $method->setAccessible(true);

        $valid = $method->invoke(
            new \App\Filters\OpsAuthFilter(),
            $request,
            $timestamp,
            $nonce,
            $signature,
            $this->testSecret
        );

        $this->assertTrue($valid, 'A historical ops/migrate signature must still validate (R-7 backward compatibility).');
    }

    /**
     * AC2: a message signed for ops/activate replayed on ops/migrate is rejected
     * with a neutral 403. The signature cannot match because the derived path is
     * ops/migrate. Fresh timestamp + unique nonce isolate the cause to signature.
     */
    public function testCrossRouteReplayIsRejected(): void
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', '');
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/activate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
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
