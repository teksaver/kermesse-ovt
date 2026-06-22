<?php

declare(strict_types=1);

use App\Models\SlotSignupModel;
use App\Models\UserModel;
use App\Services\MigrationRunnerService;
use App\Services\SlotSignupResult;
use App\Services\SlotSignupService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Story 6.6 — Signup invariants on real MariaDB 10.11.
 *
 * Validates that capacity, duplicate, and overlap constraints hold on the
 * production engine with the actual SQL schema. SQLite cannot prove these
 * because MariaDB ENUM types, FK constraints, and transaction isolation
 * semantics differ subtly.
 *
 * @group mariadb
 * @internal
 */
final class SlotSignupInvariantsMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup  = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private int $kermesseId = 0;
    private int $standId    = 0;
    private int $slotId     = 0;
    private int $ownerId    = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('SlotSignupInvariantsMariaDBTest must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        $this->dropAll($db);

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName       = 'kermesse_signup_invariants_' . uniqid();
        config('Kermesse')->opsMigrationPath           = ROOTPATH . 'database/migrations_sql';

        $result = (new MigrationRunnerService($db))->run();
        if (! $result['ok']) {
            $this->fail('Migrations failed: ' . implode(', ', $result['failed']));
        }

        $this->seedFixtures($db);
    }

    protected function tearDown(): void
    {
        $this->dropAll(db_connect('tests'));
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Happy path: successful signup is persisted in slot_signups
    // ------------------------------------------------------------------

    public function testSignupPersistsRowInSlotSignups(): void
    {
        $result = $this->makeService()->signup($this->slotId, $this->kermesseId, $this->volunteerFields());

        $this->assertInstanceOf(SlotSignupResult::class, $result);
        $this->assertTrue($result->success, 'Signup must succeed on open kermesse with free slot');

        $row = db_connect('tests')->query(
            'SELECT slot_id, email, first_name, last_name FROM `slot_signups` WHERE id = ?',
            [$result->signupId]
        )->getRowArray();

        $this->assertNotNull($row, 'Signup row must be persisted in slot_signups');
        $this->assertSame((string) $this->slotId, (string) $row['slot_id']);
        $this->assertSame('marie@kermesse.test', $row['email']);
    }

    // ------------------------------------------------------------------
    // Capacity enforcement on real ENUM/INT UNSIGNED schema
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenSlotFull(): void
    {
        $db = db_connect('tests');

        // Update slot capacity to 1 and fill it
        $db->query('UPDATE `slots` SET capacity = 1 WHERE id = ?', [$this->slotId]);
        $db->query(
            "INSERT INTO `slot_signups` (slot_id, email, first_name, last_name, phone)
             VALUES (?, 'first@kermesse.test', 'First', 'Taken', '')",
            [$this->slotId]
        );

        $result = $this->makeService()->signup($this->slotId, $this->kermesseId, $this->volunteerFields());

        $this->assertFalse($result->success);
        $this->assertSame('slot_full', $result->errorCode,
            'slot_full must be returned when slot capacity is reached on MariaDB');

        $count = (int) $db->query('SELECT COUNT(*) AS cnt FROM `slot_signups`')->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'Refused signup must not insert a row');
    }

    // ------------------------------------------------------------------
    // Duplicate signup by same email is rejected
    // ------------------------------------------------------------------

    public function testDuplicateSignupByEmailIsRejected(): void
    {
        $db = db_connect('tests');
        $db->query('UPDATE `slots` SET capacity = 5 WHERE id = ?', [$this->slotId]);

        $service = $this->makeService();

        $first = $service->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $this->assertTrue($first->success);

        $second = $service->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $this->assertFalse($second->success);
        $this->assertSame('duplicate_signup', $second->errorCode);

        $count = (int) $db->query('SELECT COUNT(*) AS cnt FROM `slot_signups`')->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'Duplicate signup must not insert a row');
    }

    // ------------------------------------------------------------------
    // Overlap conflict is rejected across two different slots
    // ------------------------------------------------------------------

    public function testOverlappingSignupIsRejected(): void
    {
        $db = db_connect('tests');
        $db->query('UPDATE `slots` SET capacity = 5 WHERE id = ?', [$this->slotId]);

        // Create a second overlapping slot (same time window)
        $db->query(
            "INSERT INTO `slots` (stand_id, starts_at, ends_at, capacity)
             VALUES (?, '2026-09-01 09:00:00', '2026-09-01 11:00:00', 5)",
            [$this->standId]
        );
        $overlappingSlotId = (int) $db->insertID();

        $service = $this->makeService();

        $first = $service->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $this->assertTrue($first->success, 'First signup must succeed');

        $second = $service->signup($overlappingSlotId, $this->kermesseId, $this->volunteerFields());
        $this->assertFalse($second->success);
        $this->assertSame('overlap_conflict', $second->errorCode,
            'Overlapping slot signup must be rejected on MariaDB');

        $count = (int) $db->query('SELECT COUNT(*) AS cnt FROM `slot_signups`')->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'Overlap rejection must leave no partial row');
    }

    public function testOverlappingSignupIsAllowedAcrossDifferentKermesses(): void
    {
        $db = db_connect('tests');
        
        // Create a second Kermesse
        $db->query(
            "INSERT INTO `kermesses` (created_by, public_slug, name, event_date, location, status)
             VALUES (?, 'other-kermesse', 'Autre Kermesse', '2026-09-01', 'Salle', 'open')",
            [$this->ownerId]
        );
        $kermesse2Id = (int) $db->insertID();

        $db->query(
            "INSERT INTO `stands` (kermesse_id, name, display_order, status) VALUES (?, 'Buvette 2', 1, 'active')",
            [$kermesse2Id]
        );
        $stand2Id = (int) $db->insertID();

        // Overlapping slot in Kermesse 2
        $db->query(
            "INSERT INTO `slots` (stand_id, starts_at, ends_at, capacity)
             VALUES (?, '2026-09-01 09:00:00', '2026-09-01 11:00:00', 5)",
            [$stand2Id]
        );
        $overlappingSlotId = (int) $db->insertID();

        $service = $this->makeService();

        $first = $service->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $this->assertTrue($first->success, 'First signup must succeed');

        $second = $service->signup($overlappingSlotId, $kermesse2Id, $this->volunteerFields());
        $this->assertTrue($second->success, 'Overlap across different kermesses is allowed');
    }

    // ------------------------------------------------------------------
    // Concurrency / Locking invariants (MariaDB FOR UPDATE)
    // ------------------------------------------------------------------

    public function testConcurrencyOnLastSeatThrowsLockWaitTimeout(): void
    {
        $db1 = db_connect('tests');
        $db2 = \Config\Database::connect('tests', false);

        // Fill slot down to 1 remaining capacity
        $db1->query('UPDATE `slots` SET capacity = 1 WHERE id = ?', [$this->slotId]);

        $db1->transBegin();
        // Connection 1 grabs the FOR UPDATE lock on the slot row
        $db1->query('SELECT id FROM `slots` WHERE id = ? FOR UPDATE', [$this->slotId]);

        // Connection 2 attempts to signup but will block on capacity check lock
        $db2->query('SET innodb_lock_wait_timeout=1');

        $service2 = new SlotSignupService(
            new UserModel($db2), new SlotSignupModel($db2), null, null, $db2
        );
        
        $start = microtime(true);
        $result = $service2->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $duration = microtime(true) - $start;

        $db1->transRollback();
        $db2->close();

        $this->assertFalse($result->success);
        $this->assertSame('transaction_failed', $result->errorCode);
        $this->assertGreaterThan(0.9, $duration, 'Connection 2 must wait for the lock');
    }

    public function testConcurrencyOnDuplicateSignupThrowsLockWaitTimeout(): void
    {
        $db1 = db_connect('tests');
        $db2 = \Config\Database::connect('tests', false);

        // Pre-insert the volunteer and an active signup
        $this->makeService()->signup($this->slotId, $this->kermesseId, $this->volunteerFields());

        $db1->transBegin();
        // Connection 1 locks the signup row during its duplicate check
        // The service uses SlotSignupModel which appends FOR UPDATE to ACTIVE_CONDITION
        $db1->query('SELECT id FROM `slot_signups` WHERE slot_id = ? AND email = ? FOR UPDATE', [$this->slotId, 'marie@kermesse.test']);

        // Connection 2 attempts a duplicate signup
        $db2->query('SET innodb_lock_wait_timeout=1');
        $service2 = new SlotSignupService(
            new UserModel($db2), new SlotSignupModel($db2), null, null, $db2
        );
        
        $start = microtime(true);
        $result = $service2->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $duration = microtime(true) - $start;

        $db1->transRollback();
        $db2->close();

        $this->assertFalse($result->success);
        $this->assertSame('transaction_failed', $result->errorCode);
        $this->assertGreaterThan(0.9, $duration, 'Connection 2 must wait for the lock');
    }

    public function testConcurrencyOnOverlapThrowsLockWaitTimeout(): void
    {
        $db1 = db_connect('tests');
        $db2 = \Config\Database::connect('tests', false);

        // Slot A
        $this->makeService()->signup($this->slotId, $this->kermesseId, $this->volunteerFields());

        // Slot B (overlapping)
        $db1->query(
            "INSERT INTO `slots` (stand_id, starts_at, ends_at, capacity)
             VALUES (?, '2026-09-01 09:30:00', '2026-09-01 10:30:00', 5)",
            [$this->standId]
        );
        $overlappingSlotId = (int) $db1->insertID();

        $db1->transBegin();
        // Connection 1 locks the overlapping signup row
        $db1->query('SELECT id FROM `slot_signups` WHERE email = ? FOR UPDATE', ['marie@kermesse.test']);

        // Connection 2 attempts to signup to the overlapping slot
        $db2->query('SET innodb_lock_wait_timeout=1');
        $service2 = new SlotSignupService(
            new UserModel($db2), new SlotSignupModel($db2), null, null, $db2
        );
        
        $start = microtime(true);
        $result = $service2->signup($overlappingSlotId, $this->kermesseId, $this->volunteerFields());
        $duration = microtime(true) - $start;

        $db1->transRollback();
        $db2->close();

        $this->assertFalse($result->success);
        $this->assertSame('transaction_failed', $result->errorCode);
        $this->assertGreaterThan(0.9, $duration, 'Connection 2 must wait for the overlap lock');
    }

    // ------------------------------------------------------------------
    // Kermesse must be open
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenKermesseNotOpen(): void
    {
        db_connect('tests')->query(
            "UPDATE `kermesses` SET status = 'preparation' WHERE id = ?",
            [$this->kermesseId]
        );

        $result = $this->makeService()->signup($this->slotId, $this->kermesseId, $this->volunteerFields());

        $this->assertFalse($result->success);
        $this->assertSame('signups_not_open', $result->errorCode);

        $count = (int) db_connect('tests')->query('SELECT COUNT(*) AS cnt FROM `slot_signups`')->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    // ------------------------------------------------------------------
    // Cancelled signup does NOT count as active capacity
    // ------------------------------------------------------------------

    public function testCancelledSignupFreesCapacityForNextVolunteer(): void
    {
        $db = db_connect('tests');
        $db->query('UPDATE `slots` SET capacity = 1 WHERE id = ?', [$this->slotId]);

        $service = $this->makeService();

        $first = $service->signup($this->slotId, $this->kermesseId, $this->volunteerFields());
        $this->assertTrue($first->success);

        // Simulate volunteer cancellation (canceled_by = user_id → volunteer cancel)
        $db->query(
            'UPDATE `slot_signups` SET canceled_at = NOW(), canceled_by = ? WHERE id = ?',
            [$first->signupId, $first->signupId]
        );

        $second = $service->signup($this->slotId, $this->kermesseId, [
            'first_name' => 'Second',
            'last_name'  => 'Volunteer',
            'email'      => 'second@kermesse.test',
            'phone'      => '',
        ]);

        $this->assertTrue($second->success,
            'Capacity freed by a cancelled signup must allow a new signup on MariaDB');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeService(): SlotSignupService
    {
        return new SlotSignupService(
            userModel:       new UserModel(),
            slotSignupModel: new SlotSignupModel(),
        );
    }

    private function volunteerFields(): array
    {
        return [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@kermesse.test',
            'phone'      => '0612345678',
        ];
    }

    private function seedFixtures(\CodeIgniter\Database\ConnectionInterface $db): void
    {
        $db->query(
            "INSERT INTO `users` (email, email_hash, first_name, last_name, phone)
             VALUES ('owner@signup-invariants.test', ?, 'Owner', 'Fixtures', '')",
            [hash('sha256', 'owner@signup-invariants.test')]
        );
        $this->ownerId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `kermesses` (created_by, public_slug, name, event_date, location, status)
             VALUES (?, 'signup-invariants-kermesse', 'Kermesse Invariants', '2026-09-01', 'Salle', 'open')",
            [$this->ownerId]
        );
        $this->kermesseId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `kermesse_user_roles` (kermesse_id, user_id, role) VALUES (?, ?, 'owner')",
            [$this->kermesseId, $this->ownerId]
        );

        $db->query(
            "INSERT INTO `stands` (kermesse_id, name, display_order, status) VALUES (?, 'Buvette', 1, 'active')",
            [$this->kermesseId]
        );
        $this->standId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `slots` (stand_id, starts_at, ends_at, capacity)
             VALUES (?, '2026-09-01 09:00:00', '2026-09-01 11:00:00', 10)",
            [$this->standId]
        );
        $this->slotId = (int) $db->insertID();
    }

    private function dropAll(\CodeIgniter\Database\ConnectionInterface $db): void
    {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $db->query('SHOW FULL TABLES WHERE TABLE_TYPE = "BASE TABLE"')->getResultArray();
        foreach ($tables as $row) {
            $db->query('DROP TABLE IF EXISTS `' . array_values($row)[0] . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
