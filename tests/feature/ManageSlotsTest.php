<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 2.3 — Ajouter et modifier des créneaux avec capacité.
 *
 * Couvre :
 * - AC1 : Ajout d'un créneau valide par Owner/Admin
 * - AC1 : Modification d'un créneau valide par Owner/Admin
 * - Rejet des rôles non autorisés (Gestionnaire, Bénévole non connecté)
 * - Validation : starts_at doit être < ends_at
 * - Validation : capacity doit être >= 1
 * - Conservation des valeurs saisies sur erreur (PRG + withInput)
 *
 * @internal
 */
final class ManageSlotsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId    = 0;
    private int $adminId    = 0;
    private int $gestionId  = 0;
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
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
                user_id      INTEGER NOT NULL,
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
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_stands_active_name ON db_stands (kermesse_id, name) WHERE status = "active"');
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
    }

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@kermesse-test.com', 'Owner', 'Test'],
            ['admin@kermesse-test.com', 'Admin', 'Test'],
            ['gestion@kermesse-test.com', 'Gestion', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '',
            ]);
        }

        $rows            = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId   = (int) $rows[0]['id'];
        $this->adminId   = (int) $rows[1]['id'];
        $this->gestionId = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'test-kermesse-23',
            'name'        => 'Kermesse Test 2.3',
            'location'    => 'Salle de test',
            'status'      => 'preparation',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,   'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->adminId,   'role' => 'admin'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->gestionId, 'role' => 'gestionnaire'],
        ]);

        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand Buvette',
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $this->standId = (int) $db->insertID();
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function csrfPost(string $url, array $data): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
    }

    private function insertSlot(string $startsAt = '2026-09-01 09:00:00', string $endsAt = '2026-09-01 11:00:00', int $capacity = 3): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $this->standId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'capacity'  => $capacity,
            'status'    => 'active',
        ]);

        return (int) $db->insertID();
    }

    // ------------------------------------------------------------------
    // AC1 — Ajout d'un créneau
    // ------------------------------------------------------------------

    public function testOwnerCanAddSlot(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '09:00',
                'ends_at'   => '11:00',
                'capacity'  => '3',
            ]);

        $result->assertRedirect();

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testAdminCanAddSlot(): void
    {
        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '14:00',
                'ends_at'   => '16:00',
                'capacity'  => '5',
            ]);

        $result->assertRedirect();

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testGestionnaireCannotAddSlot(): void
    {
        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '09:00',
                'ends_at'   => '11:00',
                'capacity'  => '3',
            ]);

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testUnauthenticatedCannotAddSlot(): void
    {
        $result = $this->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
            'starts_at' => '09:00',
            'ends_at'   => '11:00',
            'capacity'  => '3',
        ]);

        $this->assertContains($result->response()->getStatusCode(), [302, 403]);

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    // ------------------------------------------------------------------
    // AC1 — Modification d'un créneau
    // ------------------------------------------------------------------

    public function testOwnerCanUpdateSlot(): void
    {
        $slotId = $this->insertSlot('2026-09-01 09:00:00', '2026-09-01 11:00:00', 3);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/slots/{$slotId}", [
                'starts_at' => '10:00',
                'ends_at'   => '12:00',
                'capacity'  => '4',
            ]);

        $result->assertRedirect();

        $row = db_connect()
            ->query("SELECT capacity FROM db_slots WHERE id = {$slotId}")
            ->getRowArray();
        $this->assertSame(4, (int) $row['capacity']);
    }

    public function testAdminCanUpdateSlot(): void
    {
        $slotId = $this->insertSlot();

        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/slots/{$slotId}", [
                'starts_at' => '08:00',
                'ends_at'   => '10:00',
                'capacity'  => '2',
            ]);

        $result->assertRedirect();

        $row = db_connect()
            ->query("SELECT capacity FROM db_slots WHERE id = {$slotId}")
            ->getRowArray();
        $this->assertSame(2, (int) $row['capacity']);
    }

    public function testGestionnaireCannotUpdateSlot(): void
    {
        $slotId = $this->insertSlot();

        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/slots/{$slotId}", [
                'starts_at' => '09:00',
                'ends_at'   => '11:00',
                'capacity'  => '10',
            ]);

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());
    }

    public function testUpdateNonExistentSlotReturns404(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/slots/99999", [
                'starts_at' => '09:00',
                'ends_at'   => '11:00',
                'capacity'  => '3',
            ]);

        $result->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // AC2 — Validation : horaires invalides
    // ------------------------------------------------------------------

    public function testAddSlotWithStartsAtAfterEndsAtShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '12:00',
                'ends_at'   => '09:00',
                'capacity'  => '3',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testAddSlotWithStartsAtEqualEndsAtShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '10:00',
                'ends_at'   => '10:00',
                'capacity'  => '3',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testAddSlotWithMissingTimesShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '',
                'ends_at'   => '',
                'capacity'  => '3',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');
    }

    // ------------------------------------------------------------------
    // AC2 — Validation : capacité invalide
    // ------------------------------------------------------------------

    public function testAddSlotWithZeroCapacityShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '09:00',
                'ends_at'   => '11:00',
                'capacity'  => '0',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$this->standId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testAddSlotWithNegativeCapacityShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '09:00',
                'ends_at'   => '11:00',
                'capacity'  => '-1',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');
    }

    // ------------------------------------------------------------------
    // PRG — Renvoi des valeurs saisies après erreur
    // ------------------------------------------------------------------

    public function testAddSlotValidationErrorPreservesFlashData(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$this->standId}/slots", [
                'starts_at' => '12:00',
                'ends_at'   => '09:00',
                'capacity'  => '3',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');
        $result->assertSessionHas('slot_form');
        $result->assertSessionHas('slot_form_stand_id');
    }

    public function testUpdateSlotValidationErrorPreservesFlashData(): void
    {
        $slotId = $this->insertSlot();

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/slots/{$slotId}", [
                'starts_at' => '12:00',
                'ends_at'   => '09:00',
                'capacity'  => '3',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('slot_errors');
        $result->assertSessionHas('slot_form');
    }

    public function testOwnerCanDeleteSlotWithoutSignups(): void
    {
        $slotId = $this->insertSlot();

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/slots/{$slotId}/delete", []);

        $result->assertRedirect();

        $row = db_connect()
            ->query("SELECT status FROM db_slots WHERE id = {$slotId}")
            ->getRowArray();
        $this->assertSame('deactivated', $row['status']);
    }
}
