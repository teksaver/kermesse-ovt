<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Tests for the OpsAuthFilter HMAC authentication.
 *
 * These tests run without a real database (nonce storage is not exercised).
 * They verify that requests are correctly rejected for invalid credentials.
 *
 * @internal
 */
final class OpsAuthFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure for testing: disable production-only, set test secret
        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationHmacSecret = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
    }

    public function testRequestWithoutHmacHeadersIsRejected(): void
    {
        $result = $this->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testRequestWithMissingTimestampIsRejected(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Nonce'     => 'test-nonce',
            'X-Kermesse-Signature' => 'invalid-sig',
        ])->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testRequestWithMissingNonceIsRejected(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Signature' => 'invalid-sig',
        ])->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testRequestWithMissingSignatureIsRejected(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'test-nonce',
        ])->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testRequestWithInvalidSignatureIsRejected(): void
    {
        $timestamp = (string) time();
        $nonce     = 'test-nonce-invalid-sig';

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => 'definitely_not_a_valid_signature',
        ])->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testRequestWithExpiredTimestampIsRejected(): void
    {
        $expiredTimestamp = (string) (time() - 600); // 10 minutes ago, beyond 300s skew
        $nonce            = 'test-nonce-expired-ts';
        $body             = '';
        $bodyHash         = hash('sha256', $body);
        $payload          = implode("\n", [$expiredTimestamp, $nonce, 'POST', 'ops/migrate', $bodyHash]);
        $signature        = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $expiredTimestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testGetRequestIsRejected(): void
    {
        // CodeIgniter throws PageNotFoundException for GET on a POST-only route
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->get('ops/migrate');
    }

    public function testResponseDoesNotExposeHmacDetails(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'leak-test-nonce',
            'X-Kermesse-Signature' => 'invalid',
        ])->post('ops/migrate');

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString($this->testSecret, $body);
        $this->assertStringNotContainsString('hmac', strtolower($body));
        $this->assertStringNotContainsString('signature', strtolower($body));
        $this->assertStringNotContainsString('timestamp', strtolower($body));
        $this->assertStringNotContainsString('nonce', strtolower($body));
    }

    public function testEmptySecretIsRejected(): void
    {
        config('Kermesse')->opsMigrationHmacSecret = '';

        $timestamp = (string) time();
        $nonce     = 'test-nonce-empty-secret';

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => 'some-signature',
        ])->post('ops/migrate');

        $result->assertStatus(403);
    }
}
