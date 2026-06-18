<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CreateSlotDTO;
use App\Services\SlotService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Story 6.1 — Unit tests for SlotService::create().
 *
 * Verifies the service contract (not-found, persist-error, created) using a
 * real SQLite in-memory DB via the project's test infrastructure.
 *
 * @internal
 */
final class SlotServiceTest extends CIUnitTestCase
{
    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;

    private int $kermesseId = 0;
    private int $standId    = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    private function setUpTables(): void
    {
        $db = db_connect();
        $db->query('CREATE TABLE IF NOT EXISTS db_users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            email      TEXT NOT NULL,
            email_hash TEXT NOT NULL UNIQUE,
            first_name TEXT NOT NULL DEFAULT "",
            last_name  TEXT NOT NULL DEFAULT "",
            phone      TEXT NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_kermesses (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            created_by   INTEGER NOT NULL,
            public_slug  TEXT    NOT NULL UNIQUE,
            name         TEXT    NOT NULL,
            event_date   TEXT,
            location     TEXT    NOT NULL DEFAULT "",
            timezone     TEXT    NOT NULL DEFAULT "Europe/Paris",
            status       TEXT    NOT NULL DEFAULT "preparation",
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_stands (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kermesse_id   INTEGER NOT NULL,
            name          TEXT    NOT NULL,
            display_order INTEGER NOT NULL DEFAULT 0,
            status        TEXT    NOT NULL DEFAULT "active",
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $db->query('CREATE TABLE IF NOT EXISTS db_slots (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            stand_id   INTEGER NOT NULL,
            starts_at  DATETIME NOT NULL,
            ends_at    DATETIME NOT NULL,
            capacity   INTEGER  NOT NULL,
            status     TEXT     NOT NULL DEFAULT "active",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    private function insertFixtures(): void
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => 'owner@slot-svc-test.com',
            'email_hash' => hash('sha256', 'owner@slot-svc-test.com'),
        ]);
        $ownerId = (int) $db->insertID();

        $db->table('kermesses')->insert([
            'created_by'  => $ownerId,
            'public_slug' => 'slot-svc-kermesse',
            'name'        => 'Kermesse SlotService',
            'location'    => 'Test',
            'event_date'  => '2026-09-01',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand SVC',
            'display_order' => 1,
            'status'        => 'active',
        ]);
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

    public function testCreateReturnsCreatedAndPersistsActiveSlot(): void
    {
        $result = (new SlotService())->create($this->dto());

        $this->assertSame(SlotService::RESULT_CREATED, $result);

        $row = db_connect()
            ->query("SELECT starts_at, ends_at, capacity, status FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('2026-09-01 09:00:00', $row['starts_at']);
        $this->assertSame('2026-09-01 11:00:00', $row['ends_at']);
        $this->assertSame(3, (int) $row['capacity']);
        $this->assertSame('active', $row['status']);
    }

    public function testCreateReturnsNotFoundForUnknownKermesse(): void
    {
        $result = (new SlotService())->create($this->dto(kermesseId: 99999));

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) db_connect()
            ->query('SELECT COUNT(*) AS cnt FROM db_slots')
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testCreateReturnsNotFoundForUnknownStand(): void
    {
        $result = (new SlotService())->create($this->dto(standId: 99999));

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) db_connect()
            ->query('SELECT COUNT(*) AS cnt FROM db_slots')
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testCreateReturnsNotFoundForDeactivatedStand(): void
    {
        db_connect()->query(
            "UPDATE db_stands SET status = 'deactivated' WHERE id = {$this->standId}"
        );

        $result = (new SlotService())->create($this->dto());

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) db_connect()
            ->query('SELECT COUNT(*) AS cnt FROM db_slots')
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testCreateReturnsPersistErrorWhenTableUnavailable(): void
    {
        db_connect()->query('DROP TABLE IF EXISTS db_slots');
        try {
            $result = (new SlotService())->create($this->dto());
            $this->assertSame(SlotService::RESULT_PERSIST_ERROR, $result);
        } finally {
            $this->setUpTables(); // recreates db_slots via IF NOT EXISTS
        }
    }

    public function testCreateReturnsNotFoundForStandFromOtherKermesse(): void
    {
        $db = db_connect();
        $db->table('kermesses')->insert([
            'created_by'  => 1,
            'public_slug' => 'other-k',
            'name'        => 'Autre',
            'location'    => 'x',
            'status'      => 'preparation',
        ]);
        $otherKermesseId = (int) $db->insertID();

        $db->table('stands')->insert([
            'kermesse_id'   => $otherKermesseId,
            'name'          => 'Stand Autre',
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $otherStandId = (int) $db->insertID();

        // Attempt to create a slot in THIS kermesse using the OTHER kermesse's stand
        $result = (new SlotService())->create($this->dto(standId: $otherStandId));

        $this->assertSame(SlotService::RESULT_NOT_FOUND, $result);

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$otherStandId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }
}
