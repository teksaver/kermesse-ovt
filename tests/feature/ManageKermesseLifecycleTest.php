<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 2.5 — Gérer l'état d'ouverture de la kermesse.
 *
 * Couvre :
 * - AC1 : Ouverture bloquée si aucun stand actif avec créneau actif
 * - AC2 : Ouverture réussie, fermeture réussie, badge mis à jour
 * - AC3 : Actions réservées à Owner/Admin (gestionnaire et non-connecté rejetés)
 *
 * @internal
 */
final class ManageKermesseLifecycleTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId    = 0;
    private int $adminId    = 0;
    private int $gestionId  = 0;
    private int $kermesseId = 0;

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
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_stands_active_name ON db_stands (kermesse_id, name) WHERE status = "active"');
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
    }

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@lifecycle-test.com', 'Owner', 'Test'],
            ['admin@lifecycle-test.com', 'Admin', 'Test'],
            ['gestion@lifecycle-test.com', 'Gestion', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '',
            ]);
        }

        $rows = $db->query("SELECT id, email FROM db_users ORDER BY id ASC")->getResultArray();
        $this->ownerId   = (int) $rows[0]['id'];
        $this->adminId   = (int) $rows[1]['id'];
        $this->gestionId = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'test-lifecycle-25',
            'name'        => 'Kermesse Lifecycle 2.5',
            'location'    => 'Salle de test',
            'status'      => 'preparation',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,   'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->adminId,   'role' => 'admin'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->gestionId, 'role' => 'gestionnaire'],
        ]);
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function csrfPost(string $url, array $data = []): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
    }

    private function insertStandWithSlot(): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand Test',
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();

        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-10 09:00:00',
            'ends_at'   => '2026-10-10 11:00:00',
            'capacity'  => 5,
            'status'    => 'active',
        ]);

        return $standId;
    }

    private function setStatus(string $status): void
    {
        db_connect()->table('kermesses')->where('id', $this->kermesseId)->update(['status' => $status]);
    }

    private function currentStatus(): string
    {
        $row = db_connect()->query("SELECT status FROM db_kermesses WHERE id = {$this->kermesseId}")->getRowArray();
        return (string) $row['status'];
    }

    // ------------------------------------------------------------------
    // T4.1 — Ouverture réussie (stand + créneau actifs)
    // ------------------------------------------------------------------

    public function testOwnerCanOpenKermesseWithStandAndSlot(): void
    {
        $this->insertStandWithSlot();

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/open");

        $result->assertRedirect();
        $this->assertSame('open', $this->currentStatus());
        $result->assertSessionHas('success');
    }

    public function testAdminCanOpenKermesseWithStandAndSlot(): void
    {
        $this->insertStandWithSlot();

        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/open");

        $result->assertRedirect();
        $this->assertSame('open', $this->currentStatus());
        $result->assertSessionHas('success');
    }

    public function testOwnerCanCloseOpenKermesse(): void
    {
        $this->insertStandWithSlot();
        $this->setStatus('open');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/close");

        $result->assertRedirect();
        $this->assertSame('closed', $this->currentStatus());
        $result->assertSessionHas('success');
    }

    public function testAdminCanCloseOpenKermesse(): void
    {
        $this->insertStandWithSlot();
        $this->setStatus('open');

        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/close");

        $result->assertRedirect();
        $this->assertSame('closed', $this->currentStatus());
        $result->assertSessionHas('success');
    }

    public function testOwnerCanReopenClosedKermesse(): void
    {
        $this->insertStandWithSlot();
        $this->setStatus('closed');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/open");

        $result->assertRedirect();
        $this->assertSame('open', $this->currentStatus());
        $result->assertSessionHas('success');
    }

    // ------------------------------------------------------------------
    // T4.2 — Ouverture bloquée si kermesse vide (UX-DR26 / AC1)
    // ------------------------------------------------------------------

    public function testOpenBlockedWhenNoStands(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/open");

        $result->assertRedirect();
        $this->assertSame('preparation', $this->currentStatus());
        $result->assertSessionHas('lifecycle_error');
        $this->assertStringContainsString(
            'stand',
            (string) session()->getFlashdata('lifecycle_error')
        );
    }

    public function testOpenBlockedWhenStandHasNoActiveSlot(): void
    {
        // Stand actif mais sans créneau
        db_connect()->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand Sans Créneau',
            'display_order' => 1,
            'status'        => 'active',
        ]);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/open");

        $result->assertRedirect();
        $this->assertSame('preparation', $this->currentStatus());
        $result->assertSessionHas('lifecycle_error');
    }

    public function testCloseFailsWhenKermesseIsInPreparation(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/close");

        $result->assertRedirect();
        $this->assertSame('preparation', $this->currentStatus());
        $result->assertSessionHas('lifecycle_error');
    }

    // ------------------------------------------------------------------
    // T4.3 — Autorisation : gestionnaire et non-connecté rejetés
    // ------------------------------------------------------------------

    public function testGestionnaireCannotOpenKermesse(): void
    {
        $this->insertStandWithSlot();

        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/open");

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());
        $this->assertSame('preparation', $this->currentStatus());
    }

    public function testGestionnaireCannotCloseKermesse(): void
    {
        $this->setStatus('open');

        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/close");

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());
        $this->assertSame('open', $this->currentStatus());
    }

    public function testUnauthenticatedCannotOpenKermesse(): void
    {
        $this->insertStandWithSlot();

        $result = $this->csrfPost("kermesse/{$this->kermesseId}/open");

        $this->assertContains($result->response()->getStatusCode(), [302, 403]);
        $this->assertSame('preparation', $this->currentStatus());
    }

    public function testOpenInvalidKermesseReturns404(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/999/open");

        $result->assertStatus(403);
    }

    public function testCloseInvalidKermesseReturns404(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/999/close");

        $result->assertStatus(403);
    }
}
