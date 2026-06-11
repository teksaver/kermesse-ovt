<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/TmpDirTrait.php';

/**
 * MariaDB-backed feature tests for POST /ops/migrate/status.
 *
 * A valid HMAC request consumes a nonce (OpsAuthFilter writes to ops_nonces)
 * and the status endpoint reads schema_versions, so these tests require a
 * real MariaDB connection.
 *
 * @group mariadb
 * @internal
 */
final class OpsMigrateStatusEndpointMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TmpDirTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';
    private ?string $tmpDir = null;

    private bool $originalOpsMigrationProductionOnly;
    private string $originalOpsMigrationHmacSecret;
    private int $originalOpsMigrationAllowedTimestampSkew;
    private string $originalOpsMigrationPath;

    protected $DBGroup = 'tests';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('MariaDB migration status endpoint tests must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        $db->query('DROP TABLE IF EXISTS `ops_nonces`');
        $this->bootstrapSchemaVersions();
        $db->query('DELETE FROM `schema_versions`');

        $this->originalOpsMigrationProductionOnly       = config('Kermesse')->opsMigrationProductionOnly;
        $this->originalOpsMigrationHmacSecret           = config('Kermesse')->opsMigrationHmacSecret;
        $this->originalOpsMigrationAllowedTimestampSkew = config('Kermesse')->opsMigrationAllowedTimestampSkew;
        $this->originalOpsMigrationPath                 = config('Kermesse')->opsMigrationPath;

        config('Kermesse')->opsMigrationProductionOnly       = false;
        config('Kermesse')->opsMigrationHmacSecret           = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
    }

    protected function tearDown(): void
    {
        config('Kermesse')->opsMigrationProductionOnly       = $this->originalOpsMigrationProductionOnly;
        config('Kermesse')->opsMigrationHmacSecret           = $this->originalOpsMigrationHmacSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = $this->originalOpsMigrationAllowedTimestampSkew;
        config('Kermesse')->opsMigrationPath                 = $this->originalOpsMigrationPath;

        if ($this->tmpDir !== null) {
            $this->removeDirRecursive($this->tmpDir);
            $this->tmpDir = null;
        }

        parent::tearDown();
    }

    public function testValidHmacReturns200WithExpectedShape(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kermesse_status_feat_empty_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        config('Kermesse')->opsMigrationPath = $this->tmpDir;

        $result = $this->withHeaders($this->buildValidHeaders())
            ->withBody('')
            ->post('ops/migrate/status');

        $result->assertStatus(200);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertArrayHasKey('pending', $json);
        $this->assertArrayHasKey('applied', $json);
        $this->assertArrayHasKey('failed', $json);
        $this->assertIsArray($json['pending']);
        $this->assertIsArray($json['applied']);
        $this->assertIsArray($json['failed']);
    }

    public function testPendingMigrationsStillReturn200(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kermesse_status_feat_pending_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        file_put_contents($this->tmpDir . '/20260601000000_pending_test.sql', 'SELECT 1;');

        config('Kermesse')->opsMigrationPath = $this->tmpDir;

        $result = $this->withHeaders($this->buildValidHeaders())
            ->withBody('')
            ->post('ops/migrate/status');

        $result->assertStatus(200);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertContains('20260601000000_pending_test', $json['pending']);
    }

    /**
     * @return array<string, string>
     */
    private function buildValidHeaders(string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate/status', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        return [
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ];
    }

    private function bootstrapSchemaVersions(): void
    {
        db_connect('tests')->query(
            'CREATE TABLE IF NOT EXISTS `schema_versions` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `version`            VARCHAR(255)    NOT NULL,
                `checksum`           VARCHAR(64)     NOT NULL,
                `status`             ENUM(\'pending\',\'success\',\'failed\') NOT NULL DEFAULT \'pending\',
                `applied_at`         DATETIME        NULL     DEFAULT NULL,
                `execution_time_ms`  INT UNSIGNED    NULL     DEFAULT NULL,
                `error_code`         VARCHAR(10)     NULL     DEFAULT NULL,
                `error_message`      TEXT            NULL     DEFAULT NULL,
                `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_schema_versions_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }
}
