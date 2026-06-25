<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/OpsTestHelperTrait.php';

/**
 * Feature tests for POST /ops/fix-drift endpoint.
 *
 * These tests cover authentication rejection paths (no DB required).
 * Business-logic paths (actual reconciliation) live in
 * MigrationDriftReconcileMariaDBTest with @group mariadb.
 *
 * @internal
 */
final class OpsDriftFixEndpointTest extends CIUnitTestCase
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
        $result = $this->withBody('{"version":"20260614121500_add_last_login_at_to_users"}')
            ->post('ops/fix-drift');

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
        ])->withBody('{"version":"20260614121500_add_last_login_at_to_users"}')
          ->post('ops/fix-drift');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Auth — replay d'une signature ops/migrate sur ops/fix-drift → 403
    // -----------------------------------------------------------------------

    public function testCrossRouteReplayIsRejected(): void
    {
        $body = '{"version":"20260614121500_add_last_login_at_to_users"}';

        $result = $this->withHeaders($this->buildOpsHeaders('ops/migrate', $body))
            ->withBody($body)
            ->post('ops/fix-drift');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Corps vide → 400 (version_required)
    // -----------------------------------------------------------------------

    public function testEmptyVersionReturns400(): void
    {
        $body = '{}';

        $result = $this->withHeaders($this->buildOpsHeaders('ops/fix-drift', $body))
            ->withBody($body)
            ->post('ops/fix-drift');

        // With a valid HMAC, the controller handles the empty version
        $result->assertStatus(400);

        $json = json_decode($result->response()->getBody(), true);
        $this->assertFalse($json['ok']);
        $this->assertSame('version_required', $json['error']);
    }
}
