<?php

declare(strict_types=1);

use App\Services\CreateSlotDTO;
use App\Services\MigrationRunnerService;
use App\Services\SlotService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Story 6.1 — SlotService::create() against real MariaDB 11.8.6 schema.
 *
 * Validates that:
 *  - The nominal open-kermesse slot creation works with MariaDB ENUM status,
 *    INT UNSIGNED capacity, and the FK constraint from slots → stands.
 *  - Rejected requests (unknown kermesse, deactivated stand, cross-kermesse)
 *    leave no partial write in the DB.
 *
 * Must be run with database.tests.DBDriver=MySQLi (MariaDB/MySQL). Fails
 * loudly in CI if the driver is not MySQLi.
 *
 * @group mariadb
 * @internal
 */
final class ManageSlotsMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup  = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private int $kermesseId = 0;
    private int $standId    = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('ManageSlotsMariaDBTest must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        // Drop all tables with FK checks disabled so order is irrelevant.
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'slot_signups', 'signups', 'slots', 'stands', 'profile_divergences',
            'access_tokens', 'email_events', 'kermesse_user_roles',
            'kermesses', 'users', 'ops_nonces', 'schema_versions',
        ] as $table) {
            $db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        // Drop compat view created by migration 20260619500000 if present.
        $db->query('DROP VIEW IF EXISTS `slot_signups`');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Apply all actual SQL migrations — this is the authoritative MariaDB schema.
        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName       = 'kermesse_slots_mariadb_test_' . uniqid();

        $result = (new MigrationRunnerService($db))->run();
        if (!$result['ok']) {
            $this->fail('Migrations failed: ' . implode(', ', $result['failed']));
        }

        $this->insertFixtures($db);
    }

    protected function tearDown(): void
    {
        $db = db_connect('tests');
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('DELETE FROM `slot_signups`');
        $db->query('DELETE FROM `slots`');
        $db->query('DELETE FROM `stands`');
        $db->query('DELETE FROM `kermesse_user_roles`');
        $db->query('DELETE FROM `kermesses`');
        $db->query('DELETE FROM `users`');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }

    private function insertFixtures(\CodeIgniter\Database\ConnectionInterface $db): void
    {
        $db->query(
            "INSERT INTO `users` (email, email_hash, first_name, last_name, phone)
             VALUES ('owner@slots-mariadb.test', ?, 'Owner', 'MariaDB', '')",
            [hash('sha256', 'owner@slots-mariadb.test')]
        );
        $ownerId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `kermesses` (created_by, public_slug, name, event_date, location, status)
             VALUES (?, 'slots-mariadb-kermesse', 'Kermesse MariaDB Slots', '2026-09-01', 'Test', 'open')",
            [$ownerId]
        );
        $this->kermesseId = (int) $db->insertID();

        $db->query(
            "INSERT INTO `kermesse_user_roles` (kermesse_id, user_id, role) VALUES (?, ?, 'owner')",
            [$this->kermesseId, $ownerId]
        );

        $db->query(
            "INSERT INTO `stands` (kermesse_id, name, display_order, status) VALUES (?, 'Stand MariaDB', 1, 'active')",
            [$this->kermesseId]
        );
        $this->standId = (int) $db->insertID();
    }

    private function dto(
        int $kermesseId = 0,
        int $standId = 0,
        string $starts = '2026-09-01 09:00:00',
        string $ends = '2026-09-01 11:00:00',
        int $capacity = 3
    ): CreateSlotDTO {
        return new CreateSlotDTO(
            $kermesseId ?: $this->kermesseId,
            $standId    ?: $this->standId,
            $starts,
            $ends,
            $capacity,
        );
    }

    // ------------------------------------------------------------------

    /**
     * Nominal open-kermesse scenario on real MariaDB (AC7):
     * verifies ENUM status='active', INT UNSIGNED capacity, and FK integrity.
     */
    public function testCreateSlotOnOpenKermesseWithRealSchema(): void
    {
        $result = (new SlotService())->create($this->dto());

        $this->assertSame(SlotService::RESULT_CREATED, $result);

        $db  = db_connect('tests');
        $row = $db->query(
            "SELECT starts_at, ends_at, capacity, status, created_at, updated_at FROM `slots` WHERE stand_id = ?",
            [$this->standId]
        )->getRowArray();

        $this->assertNotNull($row, 'Le créneau doit être persisté en base MariaDB');
        $this->assertSame('2026-09-01 09:00:00', $row['starts_at']);
        $this->assertSame('2026-09-01 11:00:00', $row['ends_at']);
        $this->assertSame(3, (int) $row['capacity']);
        $this->assertSame('active', $row['status']);   // ENUM value
        $this->assertNotEmpty($row['created_at'], 'created_at doit être peuplé par MariaDB');
        $this->assertNotEmpty($row['updated_at'], 'updated_at doit être peuplé par MariaDB');
    }

    /**
     * Rejected requests leave no partial write (AC7).
     */
    public function testNoWriteOnNotFoundKermesse(): void
    {
        $result = (new SlotService())->create($this->dto(kermesseId: 99999));

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) db_connect('tests')
            ->query('SELECT COUNT(*) AS cnt FROM `slots`')
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testNoWriteOnDeactivatedStand(): void
    {
        db_connect('tests')->query(
            "UPDATE `stands` SET status = 'deactivated' WHERE id = ?",
            [$this->standId]
        );

        $result = (new SlotService())->create($this->dto());

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) db_connect('tests')
            ->query('SELECT COUNT(*) AS cnt FROM `slots`')
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testNoWriteOnCrossKermesseStand(): void
    {
        $db = db_connect('tests');
        $db->query(
            "INSERT INTO `kermesses` (created_by, public_slug, name, location, status) VALUES (1, 'other-mariadb-k', 'Autre', 'x', 'preparation')"
        );
        $otherKermesseId = (int) $db->insertID();
        $db->query(
            "INSERT INTO `stands` (kermesse_id, name, display_order, status) VALUES (?, 'Stand Autre', 1, 'active')",
            [$otherKermesseId]
        );
        $otherStandId = (int) $db->insertID();

        $result = (new SlotService())->create($this->dto(standId: $otherStandId));

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) $db->query(
            'SELECT COUNT(*) AS cnt FROM `slots` WHERE stand_id = ?',
            [$otherStandId]
        )->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }
}
