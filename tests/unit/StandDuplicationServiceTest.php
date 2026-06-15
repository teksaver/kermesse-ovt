<?php

use App\Services\StandDuplicationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for StandDuplicationService — Story 5.6.
 *
 * Exercises the transactional stand-duplication invariant against a real SQLite
 * test DB: slot configuration is copied (times + capacity), signups are never
 * copied, and an already-active name is rejected.
 *
 * @internal
 */
final class StandDuplicationServiceTest extends CIUnitTestCase
{
    private int $kermesseId = 1;
    private int $userId     = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_stands (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id   INTEGER NOT NULL,
                name          TEXT    NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                status        TEXT    NOT NULL DEFAULT "active",
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_stands_active_name ON db_stands (kermesse_id, name) WHERE status = "active"');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slots (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                stand_id   INTEGER NOT NULL,
                starts_at  DATETIME NOT NULL,
                ends_at    DATETIME NOT NULL,
                capacity   INTEGER  NOT NULL DEFAULT 1,
                status     TEXT     NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_signups (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                status     TEXT    NOT NULL DEFAULT "active",
                deleted_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeStand(string $name, int $order = 1): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => $name,
            'display_order' => $order,
            'status'        => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function makeSlot(int $standId, string $startsAt, string $endsAt, int $capacity, string $status = 'active'): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'capacity'  => $capacity,
            'status'    => $status,
        ]);

        return (int) $db->insertID();
    }

    private function makeSignup(int $slotId): void
    {
        db_connect()->table('signups')->insert([
            'slot_id' => $slotId,
            'user_id' => $this->userId,
            'status'  => 'active',
        ]);
    }

    private function newStandIdByName(string $name): int
    {
        return (int) db_connect()
            ->query("SELECT id FROM db_stands WHERE name = '{$name}'")
            ->getRowArray()['id'];
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testDuplicateCopiesSlotsAndReturnsSuccess(): void
    {
        $sourceId = $this->makeStand('Stand Source');
        $this->makeSlot($sourceId, '2026-07-01 09:00:00', '2026-07-01 10:00:00', 3);
        $this->makeSlot($sourceId, '2026-07-01 10:00:00', '2026-07-01 11:00:00', 5);

        $result = (new StandDuplicationService())->duplicate($this->kermesseId, $sourceId, 'Stand Cloné');

        $this->assertSame(StandDuplicationService::RESULT_SUCCESS, $result);

        $newId = $this->newStandIdByName('Stand Cloné');
        $slots = db_connect()
            ->query("SELECT starts_at, ends_at, capacity, status FROM db_slots WHERE stand_id = {$newId} ORDER BY starts_at ASC")
            ->getResultArray();

        $this->assertCount(2, $slots);
        $this->assertSame('2026-07-01 09:00:00', $slots[0]['starts_at']);
        $this->assertSame('2026-07-01 10:00:00', $slots[0]['ends_at']);
        $this->assertSame(3, (int) $slots[0]['capacity']);
        $this->assertSame('active', $slots[0]['status']);
        $this->assertSame(5, (int) $slots[1]['capacity']);
    }

    public function testDuplicateNeverCopiesSignups(): void
    {
        $sourceId = $this->makeStand('Stand Inscrit');
        $slotId   = $this->makeSlot($sourceId, '2026-07-01 09:00:00', '2026-07-01 10:00:00', 3);
        $this->makeSignup($slotId);

        $result = (new StandDuplicationService())->duplicate($this->kermesseId, $sourceId, 'Stand Vierge');

        $this->assertSame(StandDuplicationService::RESULT_SUCCESS, $result);

        $newId = $this->newStandIdByName('Stand Vierge');

        $copiedSignups = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_signups s JOIN db_slots sl ON sl.id = s.slot_id WHERE sl.stand_id = {$newId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $copiedSignups);

        $totalSignups = (int) db_connect()->query('SELECT COUNT(*) AS cnt FROM db_signups')->getRowArray()['cnt'];
        $this->assertSame(1, $totalSignups);
    }

    public function testDuplicateWithExistingActiveNameIsRejected(): void
    {
        $sourceId = $this->makeStand('Stand A', 1);
        $this->makeStand('Stand B', 2);

        $result = (new StandDuplicationService())->duplicate($this->kermesseId, $sourceId, 'Stand B');

        $this->assertSame(StandDuplicationService::RESULT_DUPLICATE_NAME, $result);

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE kermesse_id = {$this->kermesseId}")
            ->getRowArray()['cnt'];
        $this->assertSame(2, $count);
    }

    public function testDuplicateSkipsInactiveSlots(): void
    {
        $sourceId = $this->makeStand('Stand Mixte');
        $this->makeSlot($sourceId, '2026-07-01 09:00:00', '2026-07-01 10:00:00', 3, 'active');
        $this->makeSlot($sourceId, '2026-07-01 10:00:00', '2026-07-01 11:00:00', 4, 'deactivated');

        $result = (new StandDuplicationService())->duplicate($this->kermesseId, $sourceId, 'Stand Mixte Copie');

        $this->assertSame(StandDuplicationService::RESULT_SUCCESS, $result);

        $newId = $this->newStandIdByName('Stand Mixte Copie');
        $slots = db_connect()
            ->query("SELECT capacity, status FROM db_slots WHERE stand_id = {$newId}")
            ->getResultArray();

        // Seul le créneau actif est recopié ; le créneau désactivé du stand source est ignoré.
        $this->assertCount(1, $slots);
        $this->assertSame(3, (int) $slots[0]['capacity']);
        $this->assertSame('active', $slots[0]['status']);
    }

    public function testDuplicateStandWithoutSlotsCreatesEmptyStand(): void
    {
        $sourceId = $this->makeStand('Stand Vide');

        $result = (new StandDuplicationService())->duplicate($this->kermesseId, $sourceId, 'Stand Vide Copie');

        $this->assertSame(StandDuplicationService::RESULT_SUCCESS, $result);

        $newId     = $this->newStandIdByName('Stand Vide Copie');
        $slotCount = (int) db_connect()->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$newId}")->getRowArray()['cnt'];
        $this->assertSame(0, $slotCount);
    }
}
