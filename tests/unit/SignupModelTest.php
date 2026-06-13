<?php

use App\Models\SignupModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Story 4.2 — Tests unitaires de SignupModel::findActiveForUserAndKermesse().
 *
 * Invariant d'affichage de « Mes participations » : seules les inscriptions
 * ACTIVES d'un utilisateur, sur une kermesse donnée, remontent — jointes au stand
 * et au créneau (nom du stand, début, fin). Les statuts cancelled/deactivated/deleted
 * et les lignes soft-deleted (deleted_at) sont exclus, exactement comme
 * countActiveBySlotIds() : « Mes participations » et la disponibilité publique ne
 * doivent jamais diverger (UX-DR23).
 *
 * @internal
 */
final class SignupModelTest extends CIUnitTestCase
{
    private int $userId          = 0;
    private int $otherUserId     = 0;
    private int $kermesseId      = 0;
    private int $otherKermesseId = 0;
    private int $standId         = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertBaseFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testReturnsActiveSignupJoinedWithStandAndSlot(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame('Stand Buvette', $rows[0]['stand_name']);
        $this->assertSame('2026-10-10 09:00:00', $rows[0]['starts_at']);
        $this->assertSame('2026-10-10 12:00:00', $rows[0]['ends_at']);
    }

    public function testExcludesCancelledDeactivatedDeletedAndSoftDeleted(): void
    {
        $active     = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $cancelled  = $this->insertSlot($this->standId, '2026-10-10 10:00:00', '2026-10-10 11:00:00');
        $deactivated = $this->insertSlot($this->standId, '2026-10-10 11:00:00', '2026-10-10 12:00:00');
        $deleted    = $this->insertSlot($this->standId, '2026-10-10 12:00:00', '2026-10-10 13:00:00');
        $softDeleted = $this->insertSlot($this->standId, '2026-10-10 13:00:00', '2026-10-10 14:00:00');

        $this->insertSignup($active, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($cancelled, $this->userId, SignupModel::STATUS_CANCELLED);
        $this->insertSignup($deactivated, $this->userId, SignupModel::STATUS_DEACTIVATED);
        $this->insertSignup($deleted, $this->userId, SignupModel::STATUS_DELETED);
        // Statut 'active' mais soft-deleted : doit aussi être exclu (deleted_at IS NULL).
        $this->insertSignup($softDeleted, $this->userId, SignupModel::STATUS_ACTIVE, '2026-01-01 00:00:00');

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-10 09:00:00', $rows[0]['starts_at']);
    }

    public function testScopedToUserAndKermesse(): void
    {
        $slotKermesse = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');

        // Inscription active d'un AUTRE utilisateur sur la même kermesse.
        $this->insertSignup($slotKermesse, $this->otherUserId, SignupModel::STATUS_ACTIVE);

        // Inscription active de l'utilisateur, mais sur une AUTRE kermesse.
        $otherStandId = $this->insertStand($this->otherKermesseId, 'Stand Ailleurs');
        $otherSlot    = $this->insertSlot($otherStandId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $this->insertSignup($otherSlot, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertSame([], $rows);
    }

    public function testOrdersChronologicallyByStart(): void
    {
        $late  = $this->insertSlot($this->standId, '2026-10-10 14:00:00', '2026-10-10 16:00:00');
        $early = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');

        // Insérées dans le désordre : le tri doit être chronologique.
        $this->insertSignup($late, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($early, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-10 09:00:00', $rows[0]['starts_at']);
        $this->assertSame('2026-10-10 14:00:00', $rows[1]['starts_at']);
    }

    public function testReturnsEmptyArrayWhenNoActiveSignups(): void
    {
        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertSame([], $rows);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertBaseFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['benevole@signupmodel.test', 'Benevole', 'Test'],
            ['autre@signupmodel.test', 'Autre', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '',
            ]);
        }

        $rows               = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->userId       = (int) $rows[0]['id'];
        $this->otherUserId  = (int) $rows[1]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->userId,
            'public_slug' => 'signupmodel-k1',
            'name'        => 'Kermesse 4.2',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesses')->insert([
            'created_by'  => $this->userId,
            'public_slug' => 'signupmodel-k2',
            'name'        => 'Kermesse Ailleurs',
            'status'      => 'open',
        ]);
        $this->otherKermesseId = (int) $db->insertID();

        $this->standId = $this->insertStand($this->kermesseId, 'Stand Buvette');
    }

    private function insertStand(int $kermesseId, string $name): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $kermesseId,
            'name'          => $name,
            'display_order' => 1,
            'status'        => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSlot(int $standId, string $startsAt, string $endsAt): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'capacity'  => 5,
            'status'    => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, int $userId, string $status, ?string $deletedAt = null): void
    {
        db_connect()->table('signups')->insert([
            'slot_id'    => $slotId,
            'user_id'    => $userId,
            'status'     => $status,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function setUpTables(): void
    {
        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                email      TEXT    NOT NULL,
                email_hash TEXT    NOT NULL UNIQUE,
                first_name TEXT    NOT NULL DEFAULT "",
                last_name  TEXT    NOT NULL DEFAULT "",
                phone      TEXT    NOT NULL DEFAULT "",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_kermesses (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                created_by        INTEGER NOT NULL,
                public_slug       TEXT    NOT NULL UNIQUE,
                name              TEXT    NOT NULL,
                event_date        TEXT,
                location          TEXT    NOT NULL DEFAULT "",
                short_description TEXT    NOT NULL DEFAULT "",
                timezone          TEXT    NOT NULL DEFAULT "Europe/Paris",
                status            TEXT    NOT NULL DEFAULT "preparation",
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
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
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slots (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                stand_id   INTEGER NOT NULL,
                starts_at  DATETIME NOT NULL,
                ends_at    DATETIME NOT NULL,
                capacity   INTEGER  NOT NULL,
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
                deleted_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
