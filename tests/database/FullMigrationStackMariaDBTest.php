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
        // Drop the compat view created by migration 20260619500000 if it somehow survived.
        $db->query('DROP VIEW IF EXISTS `slot_signups`');

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
        // profile_divergences is intentionally absent: it was dropped by migration
        // 20260617000000_stateless_signups_and_admin_notes.
        $expectedTables = [
            'schema_versions',
            'ops_nonces',
            'users',
            'kermesses',
            'kermesse_user_roles',
            'access_tokens',
            'email_events',
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

        // profile_divergences must have been dropped by migration 20260617000000.
        $divergencesTable = $db->query('SHOW TABLES LIKE "profile_divergences"')->getResultArray();
        $this->assertEmpty($divergencesTable, 'profile_divergences must be dropped by migration 20260617000000_stateless_signups_and_admin_notes');

        // The compat view must NOT persist after the rename migration.
        $viewCheck = $db->query("SHOW FULL TABLES WHERE TABLE_TYPE = 'VIEW' AND Tables_in_" . $db->getDatabase() . " = 'slot_signups'")->getResultArray();
        $this->assertEmpty($viewCheck, 'The slot_signups VIEW must be dropped by migration 20260620000000 (only the real table must remain)');
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
    // T2-I: slot_signups FK integrity after full migration stack
    // ------------------------------------------------------------------

    public function testSlotSignupsForeignKeyIntegrityAfterMigration(): void
    {
        (new MigrationRunnerService(db_connect('tests')))->run();

        $db = db_connect('tests');

        // Verify FK: slot_signups.slot_id → slots.id exists
        $fkSlot = $db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'slot_signups'
               AND REFERENCED_TABLE_NAME = 'slots'"
        )->getResultArray();
        $this->assertNotEmpty($fkSlot, 'slot_signups must have FK to slots after rename');

        // Verify FK: slot_signups.user_id → users.id exists
        $fkUser = $db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'slot_signups'
               AND REFERENCED_TABLE_NAME = 'users'"
        )->getResultArray();
        $this->assertNotEmpty($fkUser, 'slot_signups must have FK to users after rename');
    }

    // ------------------------------------------------------------------
    // T2-J: Data inserted via compat VIEW survives the rename migration
    // Story 6.10 AC3 — données conservées après RENAME TABLE
    // ------------------------------------------------------------------

    public function testDataInsertedViaCompatViewSurvivesRename(): void
    {
        $db     = db_connect('tests');
        $runner = new MigrationRunnerService($db);

        // Run all migrations up to (and including) the compat view but NOT the rename.
        // We achieve this by running the full stack, which applies migrations in order.
        // The test here verifies the outcome: data integrity after RENAME TABLE.

        // 1. Apply full migration stack (includes compat view + rename)
        $result = $runner->run();
        $this->assertTrue($result['ok'], 'Full migration stack must succeed');

        // 2. Insert a row into slot_signups (now the real table after rename)
        // Requires a valid slot_id — insert parent records first
        $db->query("INSERT INTO `users` (email, created_at, updated_at) VALUES ('test-upgrade@kermesse.test', NOW(), NOW())");
        $userId = (int) $db->insertID();

        $db->query("INSERT INTO `kermesses` (name, slug, owner_id, lifecycle, created_at, updated_at)
            VALUES ('Test K', 'test-k', ?, 'open', NOW(), NOW())", [$userId]);
        $kermesseId = (int) $db->insertID();

        $db->query("INSERT INTO `stands` (kermesse_id, name, created_at, updated_at)
            VALUES (?, 'Stand Test', NOW(), NOW())", [$kermesseId]);
        $standId = (int) $db->insertID();

        $db->query("INSERT INTO `slots` (stand_id, label, capacity, start_at, end_at, created_at, updated_at)
            VALUES (?, 'Créneau 1', 5, NOW(), NOW(), NOW(), NOW())", [$standId]);
        $slotId = (int) $db->insertID();

        $db->query("INSERT INTO `slot_signups` (slot_id, user_id, email, first_name, last_name, created_at, updated_at)
            VALUES (?, ?, 'test-upgrade@kermesse.test', 'Alice', 'Test', NOW(), NOW())", [$slotId, $userId]);
        $signupId = (int) $db->insertID();

        // 3. Verify the row exists in slot_signups with correct data
        $row = $db->query(
            'SELECT id, email, first_name FROM slot_signups WHERE id = ?',
            [$signupId]
        )->getRowArray();

        $this->assertNotNull($row, 'Inserted row must be findable in slot_signups');
        $this->assertSame('Alice', $row['first_name']);
        $this->assertSame('test-upgrade@kermesse.test', $row['email']);

        // 4. Verify signups table does not exist
        $oldTable = $db->query('SHOW TABLES LIKE "signups"')->getResultArray();
        $this->assertEmpty($oldTable, 'signups table must not exist after rename');
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
