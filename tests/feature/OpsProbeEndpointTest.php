<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/OpsTestHelperTrait.php';

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
    use OpsTestHelperTrait;

    private \Config\Kermesse $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Kermesse');
        $this->originalConfig = clone $config;

        $this->setUpOpsConfig();
        // Probe enabled so any rejection is proven to come from the HMAC filter,
        // not from the feature gate.
        $config->opsProbeEnabled = true;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $config = config('Kermesse');
        foreach (get_object_vars($this->originalConfig) as $key => $value) {
            $config->$key = $value;
        }
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
        $result = $this->withHeaders($this->buildOpsHeaders('ops/migrate', ''))
            ->post('ops/probe');

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
