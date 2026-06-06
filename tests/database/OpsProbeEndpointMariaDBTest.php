<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * MariaDB-backed feature tests for POST /ops/probe.
 *
 * A valid HMAC request consumes a nonce (OpsAuthFilter writes to ops_nonces),
 * so these tests are not DB-free and require a real MariaDB connection.
 *
 * @group mariadb
 * @internal
 */
final class OpsProbeEndpointMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';

    protected $DBGroup = 'tests';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('MariaDB endpoint tests must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        $db->query('DROP TABLE IF EXISTS `ops_nonces`');

        config('Kermesse')->opsMigrationProductionOnly      = false;
        config('Kermesse')->opsMigrationHmacSecret          = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
    }

    /**
     * Build valid HMAC headers signing the probe route path.
     *
     * @return array<string, string>
     */
    private function buildValidHeaders(string $nonce, string $body = ''): array
    {
        $timestamp = (string) time();
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/probe', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        return [
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ];
    }

    public function testEnabledProbeReturnsRuntimeFacts(): void
    {
        config('Kermesse')->opsProbeEnabled = true;

        $headers = $this->buildValidHeaders('probe-enabled-nonce');

        $result = $this->withBody('')
            ->withHeaders($headers)
            ->post('ops/probe');

        $result->assertStatus(200);

        $json = json_decode($result->response()->getBody(), true);

        $this->assertSame(
            ['php_version', 'memory_limit', 'max_execution_time', 'post_max_size', 'upload_max_filesize', 'extensions', 'mariadb_version'],
            array_keys($json),
        );
        $this->assertIsArray($json['extensions']);
        $this->assertNotEmpty($json['extensions']);
        $this->assertNotEmpty($json['mariadb_version']);
        $this->assertSame(PHP_VERSION, $json['php_version']);

        // No secret, credential or technical detail must leak.
        $body = $result->response()->getBody();
        $this->assertStringNotContainsString($this->testSecret, $body);
        $this->assertStringNotContainsString('SELECT VERSION', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);

        $dbPassword = config('Database')->tests['password'] ?? '';
        if ($dbPassword !== '') {
            $this->assertStringNotContainsString($dbPassword, $body);
        }
    }

    public function testDisabledProbeReturnsProbeDisabled(): void
    {
        config('Kermesse')->opsProbeEnabled = false;

        $headers = $this->buildValidHeaders('probe-disabled-nonce');

        $result = $this->withBody('')
            ->withHeaders($headers)
            ->post('ops/probe');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'probe_disabled']);
    }
}
