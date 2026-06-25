<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 4.2 — Afficher « Mes participations » (bénévole).
 *
 * Couvre l'AC1 : dans la section « Mes participations » du tableau de bord, un
 * bénévole voit la liste de ses inscriptions ACTIVES (nom du stand, date, début,
 * fin). Les inscriptions annulées en sont exclues (UX-DR23). Un bénévole sans
 * inscription voit un message alternatif.
 *
 * @internal
 */
final class MyParticipationsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $benevoleId      = 0;
    private int $benevoleVideId  = 0;
    private int $kermesseId      = 0;
    private int $buvetteSlotId   = 0;
    private int $pecheSlotId     = 0;

    private const STAND_ACTIF   = 'Stand Buvette 4.2';
    private const STAND_ANNULE  = 'Stand Peche 4.2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_slot_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC1 — Le bénévole voit ses inscriptions actives
    // ------------------------------------------------------------------

    public function testBenevoleSeesActiveSignupDetails(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertSee('Mes participations');
        // Nom du stand, date et horaires du créneau actif.
        $result->assertSee(self::STAND_ACTIF);
        $result->assertSee('10/10/2026');
        $result->assertSee('09:00');
        $result->assertSee('12:00');
    }

    public function testCancelledSignupIsExcluded(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        // L'inscription annulée (et son stand) ne doit pas apparaître (UX-DR23).
        $result->assertDontSee(self::STAND_ANNULE);
    }

    public function testBenevoleWithoutSignupSeesEmptyMessage(): void
    {
        $result = $this->getDashboard($this->benevoleVideId);

        $result->assertStatus(200);
        $result->assertSee('Mes participations');
        $result->assertSee('aucune inscription active');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@my-signups.test', 'Owner', 'Test'],
            ['benevole@my-signups.test', 'Benevole', 'Test'],
            ['benevole-vide@my-signups.test', 'BenevoleVide', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '', 
            ]);
        }

        $rows                 = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $ownerId              = (int) $rows[0]['id'];
        $this->benevoleId     = (int) $rows[1]['id'];
        $this->benevoleVideId = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $ownerId,
            'public_slug' => 'my-signups-42',
            'name'        => 'Kermesse Mes Participations 4.2',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $ownerId,              'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleId,     'role' => 'benevole'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleVideId, 'role' => 'benevole'],
        ]);

        $this->buvetteSlotId = $this->insertStandWithSlot(self::STAND_ACTIF, 1, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->pecheSlotId   = $this->insertStandWithSlot(self::STAND_ANNULE, 2, '2026-10-10 14:00:00', '2026-10-10 16:00:00');

        // Le bénévole a une inscription ACTIVE (Buvette) et une ANNULÉE (Pêche).
        $this->insertSignup($this->buvetteSlotId, $this->benevoleId, 'active');
        $this->insertSignup($this->pecheSlotId, $this->benevoleId, 'cancelled');
    }

    private function insertStandWithSlot(string $standName, int $order, string $startsAt, string $endsAt): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => $standName,
            'display_order' => $order,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();

        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'capacity'  => 5,
            'status'    => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, int $userId, string $state = 'active'): void
    {
        $row = ['slot_id' => $slotId, 'user_id' => $userId];
        $row += match ($state) {
            'cancelled'              => ['canceled_at' => '2026-01-01 00:00:00', 'canceled_by' => $userId],
            'removed'                => ['canceled_at' => '2026-01-01 00:00:00', 'canceled_by' => 9999],
            'refused'                => ['rejected_at' => '2026-01-01 00:00:00'],
            'deactivated', 'deleted' => ['deleted_at' => '2026-01-01 00:00:00'],
            default                  => [],
        };
        db_connect()->table('slot_signups')->insert($row);
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function getDashboard(int $userId): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession($this->session($userId))
            ->get("kermesse/{$this->kermesseId}");
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
                last_login_at DATETIME NULL DEFAULT NULL,
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
            CREATE TABLE IF NOT EXISTS db_kermesse_user_roles (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id  INTEGER NOT NULL,
                user_id INTEGER NULL,
                role         TEXT    NOT NULL,
                invited_by   INTEGER,
                invited_at      DATETIME NULL DEFAULT NULL,
                accepted_at     DATETIME NULL DEFAULT NULL,
                first_access_at DATETIME NULL DEFAULT NULL,
                last_access_at  DATETIME NULL DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_stands (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id   INTEGER NOT NULL,
                name          TEXT    NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                status        TEXT    NOT NULL DEFAULT \'active\',
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
                status     TEXT     NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slot_signups (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id                   INTEGER  NOT NULL,
                user_id                   INTEGER  NULL,
                deleted_at                DATETIME NULL DEFAULT NULL,
                last_modified_by_user_id  INTEGER  NULL DEFAULT NULL,
                last_modified_at          DATETIME NULL DEFAULT NULL,
                first_name                TEXT     NULL DEFAULT NULL,
                last_name                 TEXT     NULL DEFAULT NULL,
                email                     TEXT     NULL DEFAULT NULL,
                phone                     TEXT     NULL DEFAULT NULL,
                admin_notes               TEXT     NULL DEFAULT NULL,
                created_by                INTEGER  NULL DEFAULT NULL,
                viewed_at                 DATETIME NULL DEFAULT NULL,
                accepted_at               DATETIME NULL DEFAULT NULL,
                rejected_at               DATETIME NULL DEFAULT NULL,
                canceled_at               DATETIME NULL DEFAULT NULL,
                canceled_by               INTEGER  NULL DEFAULT NULL,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
