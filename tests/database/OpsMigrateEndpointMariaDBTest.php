<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/OpsTestHelperTrait.php';

/**
 * MariaDB-backed feature tests for POST /ops/migrate.
 *
 * @group mariadb
 * @internal
 */
final class OpsMigrateEndpointMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use OpsTestHelperTrait;

    private string $tempMigrationsDir;

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

        $db->query('DROP TABLE IF EXISTS `endpoint_table`');
        $db->query('DROP TABLE IF EXISTS `ops_nonces`');
        $db->query('DROP TABLE IF EXISTS `schema_versions`');

        $this->tempMigrationsDir = sys_get_temp_dir() . '/kermesse_endpoint_migrations_' . uniqid();
        mkdir($this->tempMigrationsDir, 0755, true);

        $this->setUpOpsConfig();
        config('Kermesse')->opsMigrationLockName = 'kermesse_endpoint_lock_' . uniqid();
        config('Kermesse')->opsMigrationPath = $this->tempMigrationsDir;
    }

    protected function tearDown(): void
    {
        if (isset($this->tempMigrationsDir) && is_dir($this->tempMigrationsDir)) {
            $files = glob($this->tempMigrationsDir . '/*.sql') ?: [];

            foreach ($files as $file) {
                unlink($file);
            }

            rmdir($this->tempMigrationsDir);
        }

        parent::tearDown();
    }

    private function writeMigration(string $filename, string $sql): void
    {
        file_put_contents($this->tempMigrationsDir . '/' . $filename, $sql);
    }

    public function testValidRequestRunsPendingMigrationsOnBlankDatabase(): void
    {
        $this->writeMigration('20260601000000_endpoint.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `endpoint_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL
        );

        $body = '';
        $headers = $this->buildOpsHeaders('ops/migrate', $body, 'endpoint-valid-nonce');

        $result = $this->withBody($body)
            ->withHeaders($headers)
            ->post('ops/migrate');

        $result->assertStatus(200);
        $result->assertJSONExact([
            'ok' => true,
            'applied' => 1,
            'skipped' => 0,
            'failed' => 0,
        ]);

        $this->assertNotEmpty(db_connect('tests')->query('SHOW TABLES LIKE "endpoint_table"')->getResultArray());
    }

    public function testReplayedNonceIsRejectedAfterValidRequest(): void
    {
        $this->writeMigration('20260601000000_endpoint.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `endpoint_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL
        );

        $body = '';
        $headers = $this->buildOpsHeaders('ops/migrate', $body, 'endpoint-replay-nonce');

        $this->withBody($body)
            ->withHeaders($headers)
            ->post('ops/migrate')
            ->assertStatus(200);

        $result = $this->withBody($body)
            ->withHeaders($headers)
            ->post('ops/migrate');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    public function testSqlFailureResponseIsSanitised(): void
    {
        $this->writeMigration('20260601000000_bad.sql', 'THIS IS NOT VALID SQL;');

        $body = '';
        $headers = $this->buildOpsHeaders('ops/migrate', $body, 'endpoint-failure-nonce');

        $result = $this->withBody($body)
            ->withHeaders($headers)
            ->post('ops/migrate');

        $result->assertStatus(500);

        $responseBody = $result->response()->getBody();

        $this->assertStringNotContainsString('THIS IS NOT VALID SQL', $responseBody);
        $this->assertStringNotContainsString('CREATE TABLE', $responseBody);
        $this->assertStringNotContainsString('stack trace', strtolower($responseBody));
        $this->assertStringNotContainsString('.php', $responseBody);
        $this->assertStringNotContainsString($this->testSecret, $responseBody);

        $json = json_decode($responseBody, true);

        $this->assertSame([
            'ok' => false,
            'applied' => 0,
            'skipped' => 0,
            'failed' => 1,
        ], $json);
    }
}
