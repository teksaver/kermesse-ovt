<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 5.9 — Quitter une kermesse.
 *
 * Couvre :
 * - Bouton "Quitter" non rendu si inscription active (accueil connecté + dashboard)
 * - Bouton "Quitter" non rendu pour un Owner (les deux surfaces)
 * - POST leave réussi → flash succès + redirection vers /
 * - Après départ réussi, la kermesse n'apparaît plus dans l'accueil connecté
 * - POST leave avec inscription active (état périmé) → ligne conservée + flash has_active_signups
 * - POST leave en Owner → ligne conservée + flash unauthorized_role
 * - Privacy : aucune PII fuie via le bouton conditionnel
 *
 * @internal
 */
final class LeaveKermesseTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private int $ownerId    = 0;
    private int $benevoleId = 0;
    private int $adminId    = 0;
    private int $kermesseId = 0;
    private int $slotId     = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC2 — Bouton absent pour Owner
    // ------------------------------------------------------------------

    public function testLeaveButtonNotShownForOwnerOnConnectedHome(): void
    {
        $result = $this->withSession($this->session($this->ownerId))->get('/');

        $result->assertStatus(200);
        $result->assertDontSee('Quitter cette kermesse');
    }

    public function testLeaveButtonNotShownForOwnerOnDashboard(): void
    {
        $result = $this->withSession($this->session($this->ownerId))->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);
        $result->assertDontSee('Quitter cette kermesse');
    }

    // ------------------------------------------------------------------
    // AC2 — Bouton absent si inscription active
    // ------------------------------------------------------------------

    public function testLeaveButtonNotShownOnConnectedHomeWhenActiveSignup(): void
    {
        $this->insertActiveSignup($this->benevoleId);

        $result = $this->withSession($this->session($this->benevoleId))->get('/');

        $result->assertStatus(200);
        $result->assertDontSee('Quitter cette kermesse');
    }

    public function testLeaveButtonNotShownOnDashboardWhenActiveSignup(): void
    {
        $this->insertActiveSignup($this->benevoleId);

        $result = $this->withSession($this->session($this->benevoleId))->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);
        $result->assertDontSee('Quitter cette kermesse');
    }

    // ------------------------------------------------------------------
    // AC1 — Bouton visible pour bénévole sans inscription active
    // ------------------------------------------------------------------

    public function testLeaveButtonShownOnConnectedHomeForBenevoleWithoutSignup(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))->get('/');

        $result->assertStatus(200);
        $result->assertSee('Quitter cette kermesse');
    }

    public function testLeaveButtonShownOnDashboardForBenevoleWithoutSignup(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);
        $result->assertSee('Quitter cette kermesse');
    }

    // ------------------------------------------------------------------
    // AC1 — POST leave réussi → flash succès + redirection / + kermesse disparue
    // ------------------------------------------------------------------

    public function testLeaveKermesseSuccessFlashAndRedirect(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/leave");

        $result->assertRedirectTo(site_url('/'));
        $this->assertSame(
            'Vous avez quitté la kermesse.',
            (string) session()->getFlashdata('participation_notice'),
        );
    }

    public function testLeaveKermesseRemovesKermesseFromConnectedHome(): void
    {
        $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/leave");

        // Après départ la kermesse n'est plus listée sur l'accueil.
        $result = $this->withSession($this->session($this->benevoleId))->get('/');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('Kermesse Leave 5.9', $body,
            'La kermesse quittée ne doit plus apparaître dans l\'accueil connecté.');
    }

    // ------------------------------------------------------------------
    // AC4 — POST leave (état périmé) : inscription active côté serveur → rejet
    // ------------------------------------------------------------------

    public function testLeaveRejectedServerSideWhenActiveSignupExists(): void
    {
        $this->insertActiveSignup($this->benevoleId);

        $result = $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/leave");

        $result->assertRedirectTo(site_url('/'));

        // Ligne kermesse_user_roles conservée.
        $this->assertRoleExists($this->kermesseId, $this->benevoleId, 'benevole');

        $this->assertSame(
            'Vous avez encore des inscriptions actives sur cette kermesse. Annulez-les avant de la quitter.',
            (string) session()->getFlashdata('participation_error'),
        );
    }

    // ------------------------------------------------------------------
    // AC3 — POST leave en Owner → unauthorized_role flash, ligne conservée
    // ------------------------------------------------------------------

    public function testLeaveRejectedForOwner(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/leave");

        $result->assertRedirectTo(site_url('/'));

        $this->assertRoleExists($this->kermesseId, $this->ownerId, 'owner');

        $this->assertSame(
            'En tant que propriétaire, vous ne pouvez pas quitter cette kermesse.',
            (string) session()->getFlashdata('participation_error'),
        );
    }

    // ------------------------------------------------------------------
    // AC1 — Bouton visible pour Admin sans inscription active
    // ------------------------------------------------------------------

    public function testLeaveButtonShownForAdminWithoutSignup(): void
    {
        $result = $this->withSession($this->session($this->adminId))->get('/');

        $result->assertStatus(200);
        $result->assertSee('Quitter cette kermesse');
    }

    // ------------------------------------------------------------------
    // Privacy — la vue ne fuit aucune PII
    // ------------------------------------------------------------------

    public function testConnectedHomeLeaveButtonContainsNoPii(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))->get('/');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('benevole@leave59.test', $body,
            'L\'email du bénévole ne doit pas apparaître sur l\'accueil connecté.');
        $this->assertStringNotContainsString('owner@leave59.test', $body,
            'L\'email de l\'owner ne doit pas apparaître sur l\'accueil connecté.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@leave59.test',    'Owner',    'Leave'],
            ['benevole@leave59.test', 'Benevole', 'Leave'],
            ['admin@leave59.test',    'Admin',    'Leave'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '', 
            ]);
        }

        $rows              = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId    = (int) $rows[0]['id'];
        $this->benevoleId = (int) $rows[1]['id'];
        $this->adminId    = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'leave-59-feat',
            'name'        => 'Kermesse Leave 5.9',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,    'role' => 'owner',    'first_access_at' => '2026-01-01 10:00:00'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleId, 'role' => 'benevole', 'first_access_at' => '2026-01-01 10:00:00'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->adminId,    'role' => 'admin',    'first_access_at' => '2026-01-01 10:00:00'],
        ]);

        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand 5.9',
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();

        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2099-06-15 09:00:00',
            'ends_at'   => '2099-06-15 12:00:00',
            'capacity'  => 5,
        ]);
        $this->slotId = (int) $db->insertID();
    }

    private function insertActiveSignup(int $userId): void
    {
        db_connect()->table('signups')->insert([
            'slot_id' => $this->slotId,
            'user_id' => $userId,
            'status'  => 'active',
        ]);
    }

    private function assertRoleExists(int $kermesseId, int $userId, string $expectedRole): void
    {
        $row = db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $kermesseId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row, 'La ligne kermesse_user_roles doit exister.');
        $this->assertSame($expectedRole, (string) $row['role']);
    }

    private function createTables(): void
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
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id     INTEGER NOT NULL,
                user_id         INTEGER NOT NULL,
                role            TEXT    NOT NULL,
                invited_by      INTEGER,
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
                capacity   INTEGER NOT NULL DEFAULT 1,
                status     TEXT    NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_signups (
                id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id                 INTEGER NOT NULL,
                user_id                 INTEGER NOT NULL,
                status                  TEXT    NOT NULL DEFAULT "active",
                last_modified_by_user_id INTEGER NULL DEFAULT NULL,
                last_modified_at        DATETIME NULL DEFAULT NULL,
                deleted_at              DATETIME NULL DEFAULT NULL,
                created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
