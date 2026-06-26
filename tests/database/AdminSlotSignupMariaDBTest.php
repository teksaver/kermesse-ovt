<?php

declare(strict_types=1);

use App\Models\SlotSignupModel;
use App\Models\UserModel;
use App\Services\AdminMoveSlotSignupDTO;
use App\Services\MigrationRunnerService;
use App\Services\SlotSignupService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Story 6.6 — Admin slot-signup operations atomicity on real MariaDB 11.8.6.
 *
 * Validates that adminCancelSlotSignup and moveSlotSignup are atomic on the
 * production engine: either the full operation commits or no state changes.
 * SQLite does not enforce FK constraints or ENUM types, so these guarantees
 * can only be proved on MariaDB.
 *
 * @group mariadb
 * @internal
 */
final class AdminSlotSignupMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup  = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private int $kermesseId  = 0;
    private int $standId     = 0;
    private int $slotAId     = 0;
    private int $slotBId     = 0;
    private int $adminId     = 0;
    private int $volunteerId = 0;
    private int $signupId    = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('AdminSlotSignupMariaDBTest must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        $this->dropAll($db);

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName       = 'kermesse_admin_signup_' . uniqid();
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
    // T4-A: Admin cancel marks signup as removed and stamps admin fields
    // ------------------------------------------------------------------

    public function testAdminCancelMarksSignupAsRemovedOnMariaDB(): void
    {
        $result = $this->makeService()->adminCancelSlotSignup(
            slotSignupId: $this->signupId,
            adminUserId:  $this->adminId,
            kermesseId:   $this->kermesseId,
            notify:       false,
        );

        $this->assertTrue($result->success, 'Admin cancel must succeed on MariaDB');

        $row = db_connect('tests')->query(
            'SELECT canceled_at, canceled_by, last_modified_by_user_id FROM `slot_signups` WHERE id = ?',
            [$this->signupId]
        )->getRowArray();

        $this->assertNotNull($row['canceled_at'],
            'canceled_at must be set after admin cancel');
        $this->assertSame((string) $this->adminId, (string) $row['canceled_by'],
            'canceled_by must carry the admin user id (admin cancel, not volunteer)');
        $this->assertSame((string) $this->adminId, (string) $row['last_modified_by_user_id'],
            'last_modified_by_user_id must be stamped after admin cancel');
    }

    // ------------------------------------------------------------------
    // T4-B: Admin cancel on unknown signup returns not_found, no mutation
    // ------------------------------------------------------------------

    public function testAdminCancelOnUnknownSignupReturnsNotFound(): void
    {
        $result = $this->makeService()->adminCancelSlotSignup(
            slotSignupId: 99999,
            adminUserId:  $this->adminId,
            kermesseId:   $this->kermesseId,
        );

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);

        // Original signup must be untouched
        $row = db_connect('tests')->query(
            'SELECT canceled_at FROM `slot_signups` WHERE id = ?',
            [$this->signupId]
        )->getRowArray();
        $this->assertNull($row['canceled_at'], 'Original signup must not be mutated');
    }

    // ------------------------------------------------------------------
    // T4-C: Move is atomic — source is cancelled AND new signup is created together
    // ------------------------------------------------------------------

    public function testMoveSlotSignupIsAtomicOnMariaDB(): void
    {
        $dto = new AdminMoveSlotSignupDTO(
            sourceSlotSignupId:    $this->signupId,
            targetSlotId:          $this->slotBId,
            kermesseId:            $this->kermesseId,
            adminUserId:           $this->adminId,
            sendNotificationEmail: false,
        );

        $result = $this->makeService()->moveSlotSignup($dto);

        $this->assertTrue($result->success, 'Move must succeed on MariaDB');

        $db = db_connect('tests');

        // Source signup must now be cancelled (removed by admin)
        $source = $db->query(
            'SELECT canceled_at, canceled_by FROM `slot_signups` WHERE id = ?',
            [$this->signupId]
        )->getRowArray();
        $this->assertNotNull($source['canceled_at'], 'Source signup must be cancelled after move');
        $this->assertSame((string) $this->adminId, (string) $source['canceled_by'],
            'canceled_by must carry the admin id on the source signup');

        // New signup must exist on slotB
        $newSignup = $db->query(
            'SELECT id, slot_id, email FROM `slot_signups` WHERE id = ? AND slot_id = ?',
            [$result->signupId, $this->slotBId]
        )->getRowArray();
        $this->assertNotNull($newSignup, 'A new signup must be created on the target slot');
        $this->assertSame('marie@kermesse.test', $newSignup['email']);

        // Total active signups: original cancelled (inactive) + new on slotB (active)
        $activeCount = (int) $db->query(
            'SELECT COUNT(*) AS cnt FROM `slot_signups` WHERE canceled_at IS NULL AND deleted_at IS NULL'
        )->getRowArray()['cnt'];
        $this->assertSame(1, $activeCount,
            'Exactly 1 active signup must exist after move (source cancelled, target created)');
    }

    // ------------------------------------------------------------------
    // T4-C2: Admin edit is atomic (Story 5.10 AC2)
    // ------------------------------------------------------------------

    public function testAdminEditSlotSignupRollsBackOnFailure(): void
    {
        $db = db_connect('tests');

        // Drop the email column to force an SQL exception during updateContactFields
        // But since this is a shared DB, we can't safely drop columns without breaking other tests.
        // Instead, we pass an invalid array key or cause a database error.
        // Actually, we can use a mock for the model. But we want to test MariaDB atomicity.
        // Let's cause a constraint violation.
        // email is a varchar(255). Phone is varchar(32). If we send a 1000-char string for phone,
        // MariaDB will throw a Data too long exception (in strict mode).
        
        $longPhone = str_repeat('0', 100);

        $service = $this->makeService();
        $result = $service->adminEditSlotSignup(
            $this->signupId,
            $this->adminId,
            $this->kermesseId,
            ['phone' => $longPhone]
        );

        $this->assertFalse($result->success);
        $this->assertSame('edit_failed', $result->errorCode);

        // Verify that the tracking columns (last_modified_by_user_id) were NOT stamped 
        // because the transaction rolled back.
        $row = $db->query(
            'SELECT phone, last_modified_by_user_id FROM `slot_signups` WHERE id = ?',
            [$this->signupId]
        )->getRowArray();

        $this->assertNull($row['last_modified_by_user_id'], 'The modification stamp must be rolled back');
        $this->assertSame('0612345678', $row['phone'], 'The phone number must remain unchanged');
    }

    // ------------------------------------------------------------------
    // T4-D: Move to full slot rolls back and leaves source intact
    // ------------------------------------------------------------------

    public function testMoveToFullSlotRollsBackSourceIntact(): void
    {
        $db = db_connect('tests');

        // Fill slotB to capacity 0 (impossible capacity → slot_full)
        $db->query('UPDATE `slots` SET capacity = 0 WHERE id = ?', [$this->slotBId]);

        $dto = new AdminMoveSlotSignupDTO(
            sourceSlotSignupId:    $this->signupId,
            targetSlotId:          $this->slotBId,
            kermesseId:            $this->kermesseId,
            adminUserId:           $this->adminId,
            sendNotificationEmail: false,
        );

        $result = $this->makeService()->moveSlotSignup($dto);

        $this->assertFalse($result->success);
        $this->assertSame('slot_full', $result->errorCode);

        // Source signup must be untouched (transaction rolled back)
        $source = $db->query(
            'SELECT canceled_at FROM `slot_signups` WHERE id = ?',
            [$this->signupId]
        )->getRowArray();
        $this->assertNull($source['canceled_at'],
            'Source signup must not be cancelled when move fails (transaction rolled back)');

        // No additional signup must have been created
        $count = (int) $db->query(
            'SELECT COUNT(*) AS cnt FROM `slot_signups`'
        )->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'No new signup row must exist after failed move');
    }

    // ------------------------------------------------------------------
    // T4-E: Admin cancel frees capacity immediately
    // ------------------------------------------------------------------

    public function testAdminCancelFreesCapacityForNewSignup(): void
    {
        $db = db_connect('tests');

        // Set slot A capacity to 1 (already filled by the seed signup)
        $db->query('UPDATE `slots` SET capacity = 1 WHERE id = ?', [$this->slotAId]);

        // Cancel the existing signup
        $cancel = $this->makeService()->adminCancelSlotSignup(
            slotSignupId: $this->signupId,
            adminUserId:  $this->adminId,
            kermesseId:   $this->kermesseId,
        );
        $this->assertTrue($cancel->success);

        // A new volunteer must now be able to sign up to slot A
        $newSignup = $this->makeService()->signup(
            $this->slotAId,
            $this->kermesseId,
            [
                'first_name' => 'New',
                'last_name'  => 'Volunteer',
                'email'      => 'new@kermesse.test',
                'phone'      => '',
            ]
        );

        $this->assertTrue($newSignup->success,
            'Capacity freed by admin cancel must allow a new signup on MariaDB');
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

    private function seedFixtures(\CodeIgniter\Database\ConnectionInterface $db): void
    {
        $db->query(
            "INSERT INTO `users` (email, email_hash, first_name, last_name, phone)
             VALUES ('admin@admin-signup.test', ?, 'Admin', 'User', '')",
            [hash('sha256', 'admin@admin-signup.test')]
        );
        $this->adminId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `users` (email, email_hash, first_name, last_name, phone)
             VALUES ('marie@kermesse.test', ?, 'Marie', 'Dupont', '0612345678')",
            [hash('sha256', 'marie@kermesse.test')]
        );
        $this->volunteerId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `kermesses` (created_by, public_slug, name, event_date, location, status)
             VALUES (?, 'admin-signup-kermesse', 'Kermesse Admin Signup', '2026-09-01', 'Salle', 'open')",
            [$this->adminId]
        );
        $this->kermesseId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `kermesse_user_roles` (kermesse_id, user_id, role) VALUES (?, ?, 'owner')",
            [$this->kermesseId, $this->adminId]
        );

        $db->query(
            "INSERT INTO `stands` (kermesse_id, name, display_order, status) VALUES (?, 'Stand Admin', 1, 'active')",
            [$this->kermesseId]
        );
        $this->standId = (int) $db->insertID();

        // Slot A — will receive the initial signup
        $db->query(
            "INSERT INTO `slots` (stand_id, starts_at, ends_at, capacity)
             VALUES (?, '2026-09-01 09:00:00', '2026-09-01 11:00:00', 5)",
            [$this->standId]
        );
        $this->slotAId = (int) $db->insertID();

        // Slot B — target for move operations
        $db->query(
            "INSERT INTO `slots` (stand_id, starts_at, ends_at, capacity)
             VALUES (?, '2026-09-01 14:00:00', '2026-09-01 16:00:00', 5)",
            [$this->standId]
        );
        $this->slotBId = (int) $db->insertID();

        // Initial signup on slot A
        $db->query(
            "INSERT INTO `slot_signups` (slot_id, user_id, email, first_name, last_name, phone)
             VALUES (?, ?, 'marie@kermesse.test', 'Marie', 'Dupont', '0612345678')",
            [$this->slotAId, $this->volunteerId]
        );
        $this->signupId = (int) $db->insertID();
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
