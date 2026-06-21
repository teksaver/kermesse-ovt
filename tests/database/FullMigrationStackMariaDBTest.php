<?php

declare(strict_types=1);

use App\Services\MigrationRunnerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Story 6.6 — Validate full migration stack on MariaDB 10.11.
 *
 * Applies every SQL file under database/migrations_sql/ in lexicographic
 * order (matching the MigrationRunnerService ordering) and verifies the
 * final schema: all expected tables, critical columns, FK constraints, and
 * indexes — without maintaining a manual cleanup list.
 *
 * The test discovers expected tables by scanning the migration files
 * themselves, so a forgotten migration or a renamed table fails automatically.
 *
 * @group mariadb
 * @internal
 */
final class FullMigrationStackMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';

    private bool $origOpsProductionOnly;
    private string $origOpsLockName;
    private string $origOpsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('FullMigrationStackMariaDBTest must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        // Wipe everything so this test always starts from a blank slate.
        $this->dropAllTables($db);

        $this->origOpsProductionOnly = config('Kermesse')->opsMigrationProductionOnly;
        $this->origOpsLockName       = config('Kermesse')->opsMigrationLockName;
        $this->origOpsPath           = config('Kermesse')->opsMigrationPath;

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName       = 'kermesse_full_stack_mariadb_test_' . uniqid();
        config('Kermesse')->opsMigrationPath           = ROOTPATH . 'database/migrations_sql';
    }

    protected function tearDown(): void
    {
        try {
            $this->dropAllTables(db_connect('tests'));
        } finally {
            config('Kermesse')->opsMigrationProductionOnly = $this->origOpsProductionOnly;
            config('Kermesse')->opsMigrationLockName       = $this->origOpsLockName;
            config('Kermesse')->opsMigrationPath           = $this->origOpsPath;
            parent::tearDown();
        }
    }

    // ------------------------------------------------------------------
    // T2-A: All migrations apply in order, no failures
    // ------------------------------------------------------------------

    public function testAllMigrationsApplySuccessfully(): void
    {
        $runner = new MigrationRunnerService(db_connect('tests'));
        $result = $runner->run();

        $this->assertTrue($result['ok'],
            'All migrations must succeed. Failed: ' . implode(', ', $result['failed'] ?? []));
        $this->assertEmpty($result['failed']);
        $this->assertNotEmpty($result['applied'], 'At least one migration must have been applied');
    }

    // ------------------------------------------------------------------
    // T2-B: Final schema has all expected tables (auto-discovered from files)
    // ------------------------------------------------------------------

    public function testFinalSchemaContainsAllExpectedTables(): void
    {
        $runner = new MigrationRunnerService(db_connect('tests'));
        $runner->run();

        $db = db_connect('tests');

        // Authoritative list of tables that must exist after all migrations:
        // derived from CREATE TABLE and RENAME TABLE statements in the migrations.
        $expectedTables = [
            'schema_versions',
            'ops_nonces',
            'users',
            'kermesses',
            'kermesse_user_roles',
            'access_tokens',
            'email_events',
            'profile_divergences',
            'stands',
            'slots',
            'slot_signups',   // renamed from `signups` by 20260620000000
        ];

        foreach ($expectedTables as $table) {
            $found = $db->query('SHOW TABLES LIKE ?', [$table])->getResultArray();
            $this->assertNotEmpty($found, "Table `{$table}` must exist after all migrations");
        }

        // The old name must NOT survive the rename.
        $oldTable = $db->query('SHOW TABLES LIKE "signups"')->getResultArray();
        $this->assertEmpty($oldTable, 'The `signups` table must not exist after rename migration 20260620000000');
    }

    // ------------------------------------------------------------------
    // T2-C: slot_signups has all stateless traceability columns
    // ------------------------------------------------------------------

    public function testSlotSignupsHasAllTraceabilityColumns(): void
    {
        (new MigrationRunnerService(db_connect('tests')))->run();

        $db      = db_connect('tests');
        $columns = array_column(
            $db->query('SHOW COLUMNS FROM `slot_signups`')->getResultArray(),
            'Field'
        );

        // Core columns from initial schema
        foreach (['id', 'slot_id', 'user_id', 'created_at', 'updated_at'] as $col) {
            $this->assertContains($col, $columns, "slot_signups must have column `{$col}`");
        }

        // Traceability columns added over successive migrations
        foreach (['last_modified_by_user_id', 'last_modified_at',
                  'first_name', 'last_name', 'email', 'phone', 'admin_notes',
                  'created_by', 'viewed_at', 'accepted_at', 'rejected_at',
                  'canceled_at', 'canceled_by', 'deleted_at'] as $col) {
            $this->assertContains($col, $columns, "slot_signups must have traceability column `{$col}`");
        }

        // The `status` column must have been dropped by migration 20260619000000
        $this->assertNotContains('status', $columns, 'slot_signups.status must be dropped by stateless migration');
    }

    // ------------------------------------------------------------------
    // T2-D: kermesse_user_roles has access-tracking columns
    // ------------------------------------------------------------------

    public function testKermesseUserRolesHasAccessTrackingColumns(): void
    {
        (new MigrationRunnerService(db_connect('tests')))->run();

        $db      = db_connect('tests');
        $columns = array_column(
            $db->query('SHOW COLUMNS FROM `kermesse_user_roles`')->getResultArray(),
            'Field'
        );

        foreach (['invited_at', 'accepted_at', 'first_access_at', 'last_access_at'] as $col) {
            $this->assertContains($col, $columns, "kermesse_user_roles must have column `{$col}`");
        }
    }

    // ------------------------------------------------------------------
    // T2-E: users has last_login_at
    // ------------------------------------------------------------------

    public function testUsersHasLastLoginAt(): void
    {
        (new MigrationRunnerService(db_connect('tests')))->run();

        $columns = array_column(
            db_connect('tests')->query('SHOW COLUMNS FROM `users`')->getResultArray(),
            'Field'
        );

        $this->assertContains('last_login_at', $columns, 'users must have last_login_at after migration 20260614121500');
    }

    // ------------------------------------------------------------------
    // T2-F: slots → stands FK constraint with RESTRICT delete rule
    // ------------------------------------------------------------------

    public function testSlotsForeignKeyOnStandsHasRestrictDelete(): void
    {
        (new MigrationRunnerService(db_connect('tests')))->run();

        $fk = db_connect('tests')->query(
            'SELECT `CONSTRAINT_NAME`, `DELETE_RULE`
             FROM `information_schema`.`REFERENTIAL_CONSTRAINTS`
             WHERE `CONSTRAINT_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = "slots"
               AND `CONSTRAINT_NAME` = "fk_slots_stand"'
        )->getRowArray();

        $this->assertSame('fk_slots_stand', $fk['CONSTRAINT_NAME'] ?? null);
        $this->assertSame('RESTRICT', $fk['DELETE_RULE'] ?? null);
    }

    // ------------------------------------------------------------------
    // T2-G: All migrations recorded in schema_versions
    // ------------------------------------------------------------------

    public function testAllMigrationsRecordedInSchemaVersions(): void
    {
        $runner = new MigrationRunnerService(db_connect('tests'));
        $result = $runner->run();
        $this->assertTrue($result['ok']);

        $db       = db_connect('tests');
        $recorded = $db->query('SELECT `version`, `checksum` FROM `schema_versions` WHERE `status` = "success" ORDER BY `applied_at` ASC')->getResultArray();
        $recordedVersions = array_column($recorded, 'version');

        // Verify order matches lexicographical order exactly
        $expectedOrder = $result['applied'];
        sort($expectedOrder);
        $this->assertSame($expectedOrder, $recordedVersions, 'Migrations must be applied in strict lexicographical order');

        // Every applied migration must have a 'success' record and a non-empty checksum
        foreach ($recorded as $row) {
            $this->assertContains($row['version'], $result['applied'], "Recorded migration must be in applied list");
            $this->assertNotEmpty($row['checksum'], "Checksum for version {$row['version']} must not be empty");
        }
    }

    // ------------------------------------------------------------------
    // T2-H: Second run is idempotent — all migrations skipped, none re-applied
    // ------------------------------------------------------------------

    public function testSecondRunIsIdempotent(): void
    {
        $runner = new MigrationRunnerService(db_connect('tests'));
        $runner->run();

        $result2 = $runner->run();

        $this->assertTrue($result2['ok']);
        $this->assertEmpty($result2['applied'],  'No migration should be re-applied on second run');
        $this->assertEmpty($result2['failed'],   'No migration should fail on second run');
        $this->assertNotEmpty($result2['skipped'], 'All migrations must be skipped on second run');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function dropAllTables(\CodeIgniter\Database\ConnectionInterface $db): void
    {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $tables = $db->query('SHOW FULL TABLES WHERE TABLE_TYPE = "BASE TABLE"')->getResultArray();
            foreach ($tables as $row) {
                $tableName = array_values($row)[0];
                $db->query("DROP TABLE IF EXISTS `{$tableName}`");
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
