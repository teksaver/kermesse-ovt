<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/OpsTestHelperTrait.php';

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
    use OpsTestHelperTrait;

    private \Config\Kermesse $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Kermesse');
        $this->originalConfig = clone $config;
        $this->setUpOpsConfig();
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
        $result = $this->withHeaders($this->buildOpsHeaders('ops/migrate', ''))
            ->post('ops/migrate/status');

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
