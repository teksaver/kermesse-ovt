<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Services\MigrationRunnerService;

/**
 * Integration tests for MigrationRunnerService against MariaDB.
 *
 * These tests require a running MariaDB instance configured via
 * environment variables (database.tests.*).
 *
 * @group mariadb
 * @internal
 */
final class MigrationRunnerMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** @var string Temporary directory for test migration files */
    private string $tempMigrationsDir;

    protected $DBGroup = 'tests';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        // Skip if not running against MySQL/MariaDB
        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('MariaDB tests must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        // Ensure we start clean — drop tables if they exist
        $db->query('DROP TABLE IF EXISTS `quoted_table`');
        $db->query('DROP TABLE IF EXISTS `second_table`');
        $db->query('DROP TABLE IF EXISTS `email_events`');
        $db->query('DROP TABLE IF EXISTS `access_tokens`');
        $db->query('DROP TABLE IF EXISTS `kermesses`');
        $db->query('DROP TABLE IF EXISTS `owners`');
        $db->query('DROP TABLE IF EXISTS `ops_nonces`');
        $db->query('DROP TABLE IF EXISTS `schema_versions`');
        $db->query('DROP TABLE IF EXISTS `test_table`');

        // Set up temp migrations directory
        $this->tempMigrationsDir = sys_get_temp_dir() . '/kermesse_test_migrations_' . uniqid();
        mkdir($this->tempMigrationsDir, 0755, true);

        // Configure runner to use test DB and temp migrations dir
        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName = 'kermesse_test_lock_' . uniqid();
        config('Kermesse')->opsMigrationPath = $this->tempMigrationsDir;
    }

    protected function tearDown(): void
    {
        // Clean up temp migrations
        if (is_dir($this->tempMigrationsDir)) {
            array_map('unlink', glob($this->tempMigrationsDir . '/*.sql'));
            rmdir($this->tempMigrationsDir);
        }

        parent::tearDown();
    }

    /**
     * Create a MigrationRunnerService pointing to the temp migrations dir.
     */
    private function createRunner(): MigrationRunnerService
    {
        return new MigrationRunnerService(db_connect('tests'));
    }

    /**
     * Write a migration SQL file to the temp directory.
     */
    private function writeMigration(string $filename, string $sql): void
    {
        file_put_contents($this->tempMigrationsDir . '/' . $filename, $sql);
    }

    public function testRunnerAppliesPendingMigration(): void
    {
        $this->writeMigration('20260601000000_test.sql', <<<'SQL'
            CREATE TABLE `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL
        );

        $runner = $this->createRunner();
        $result = $runner->run();

        $this->assertTrue($result['ok']);
        $this->assertContains('20260601000000_test', $result['applied']);
        $this->assertEmpty($result['failed']);

        // Verify table was created
        $db = db_connect('tests');
        $tables = $db->query('SHOW TABLES LIKE "test_table"')->getResultArray();
        $this->assertNotEmpty($tables);
    }

    public function testRunnerSkipsAlreadyAppliedMigration(): void
    {
        $this->writeMigration('20260601000000_test.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL
        );

        $runner = $this->createRunner();

        // First run — apply
        $result1 = $runner->run();
        $this->assertTrue($result1['ok']);
        $this->assertContains('20260601000000_test', $result1['applied']);

        // Second run — skip
        $result2 = $runner->run();
        $this->assertTrue($result2['ok']);
        $this->assertContains('20260601000000_test', $result2['skipped']);
        $this->assertEmpty($result2['applied']);
    }

    public function testRunnerRefusesChecksumDrift(): void
    {
        $originalSql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL;

        $this->writeMigration('20260601000000_test.sql', $originalSql);
        $originalChecksum = hash('sha256', $originalSql);

        $runner = $this->createRunner();
        $result1 = $runner->run();
        $this->assertTrue($result1['ok']);

        // Modify the migration file content (simulating drift)
        $modifiedSql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `extra_column` VARCHAR(100) DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL;

        $this->writeMigration('20260601000000_test.sql', $modifiedSql);
        $modifiedChecksum = hash('sha256', $modifiedSql);

        // Second run — should refuse due to checksum mismatch
        $result2 = $runner->run();
        $this->assertFalse($result2['ok']);
        $this->assertContains('20260601000000_test', $result2['failed']);

        $row = db_connect('tests')->query(
            'SELECT `checksum`, `status`, `error_code` FROM `schema_versions` WHERE `version` = ?',
            ['20260601000000_test']
        )->getRowArray();

        $this->assertSame('success', $row['status']);
        $this->assertSame($originalChecksum, $row['checksum']);
        $this->assertNotSame($modifiedChecksum, $row['checksum']);
        $this->assertSame('DRIFT', $row['error_code']);
    }

    public function testRunnerCanRetryIdempotentMigrationAfterPartialFailure(): void
    {
        $this->writeMigration('20260601000000_partial.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            THIS IS NOT VALID SQL;
            SQL
        );

        $runner = $this->createRunner();
        $result1 = $runner->run();

        $this->assertFalse($result1['ok']);
        $this->assertContains('20260601000000_partial', $result1['failed']);

        $this->writeMigration('20260601000000_partial.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            CREATE TABLE IF NOT EXISTS `second_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL
        );

        $result2 = $runner->run();

        $this->assertTrue($result2['ok']);
        $this->assertContains('20260601000000_partial', $result2['applied']);

        $db = db_connect('tests');
        $this->assertNotEmpty($db->query('SHOW TABLES LIKE "test_table"')->getResultArray());
        $this->assertNotEmpty($db->query('SHOW TABLES LIKE "second_table"')->getResultArray());
    }

    public function testRunnerHandlesSemicolonsInsideSqlStringsAndComments(): void
    {
        $this->writeMigration('20260601000000_quoted.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `quoted_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `label` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            INSERT INTO `quoted_table` (`label`) VALUES ('value;with;semicolons');
            -- this comment has a ; and should not split
            INSERT INTO `quoted_table` (`label`) VALUES ("double;quoted");
            SQL
        );

        $runner = $this->createRunner();
        $result = $runner->run();

        $this->assertTrue($result['ok']);

        $count = db_connect('tests')->query('SELECT COUNT(*) AS `count` FROM `quoted_table`')->getRowArray();
        $this->assertSame(2, (int) $count['count']);
    }

    public function testRunnerRecordsInSchemaVersions(): void
    {
        $this->writeMigration('20260601000000_test.sql', <<<'SQL'
            CREATE TABLE IF NOT EXISTS `test_table` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            SQL
        );

        $runner = $this->createRunner();
        $runner->run();

        $db = db_connect('tests');
        $row = $db->query(
            'SELECT * FROM `schema_versions` WHERE `version` = ?',
            ['20260601000000_test']
        )->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('success', $row['status']);
        $this->assertNotNull($row['applied_at']);
        $this->assertNotNull($row['execution_time_ms']);
        $this->assertNotEmpty($row['checksum']);
    }

    public function testInitialSchemaMigrationRunsSuccessfully(): void
    {
        // Use the actual initial schema migration
        $schemaFile = ROOTPATH . 'database/migrations_sql/20260602161500_initial_schema.sql';
        $this->assertTrue(file_exists($schemaFile), 'Initial schema file must exist');

        copy($schemaFile, $this->tempMigrationsDir . '/20260602161500_initial_schema.sql');

        $runner = $this->createRunner();
        $result = $runner->run();

        $this->assertTrue($result['ok']);
        $this->assertContains('20260602161500_initial_schema', $result['applied']);

        // Verify all expected tables exist
        $db = db_connect('tests');
        $expectedTables = ['schema_versions', 'ops_nonces', 'owners', 'kermesses', 'access_tokens', 'email_events'];

        foreach ($expectedTables as $table) {
            $found = $db->query('SHOW TABLES LIKE ?', [$table])->getResultArray();
            $this->assertNotEmpty($found, "Table '{$table}' should exist after initial migration");
        }
    }

    public function testRunnerReleasesLockAfterFailure(): void
    {
        // Write a migration that will fail
        $this->writeMigration('20260601000000_bad.sql', 'THIS IS NOT VALID SQL;');

        $runner = $this->createRunner();
        $result = $runner->run();

        $this->assertFalse($result['ok']);
        $this->assertContains('20260601000000_bad', $result['failed']);

        // Verify the lock was released by acquiring it again
        $db = db_connect('tests');
        $lockName = config('Kermesse')->opsMigrationLockName;
        $lockResult = $db->query('SELECT GET_LOCK(?, 1) AS acquired', [$lockName])->getRowArray();
        $this->assertEquals(1, $lockResult['acquired'], 'Lock should be available after failed migration');
        $db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}
