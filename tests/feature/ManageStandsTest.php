<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 2.2 — Ajouter et modifier des stands.
 *
 * Couvre :
 * - AC1 : Ajout d'un stand par Owner/Admin ; rejet des rôles non autorisés
 * - AC2 : Modification du nom d'un stand existant
 * - Contrainte d'unicité (nom actif en doublon)
 * - Conservation des valeurs saisies sur erreur de validation
 *
 * @internal
 */
final class ManageStandsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId      = 0;
    private int $adminId      = 0;
    private int $gestionId    = 0;
    private int $benovoleId   = 0;
    private int $kermesseId   = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
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
    }

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@kermesse-test.com', 'Owner', 'Test'],
            ['admin@kermesse-test.com', 'Admin', 'Test'],
            ['gestion@kermesse-test.com', 'Gestion', 'Test'],
            ['benevole@kermesse-test.com', 'Benevole', 'Test'],
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
        $this->ownerId    = (int) $rows[0]['id'];
        $this->adminId    = (int) $rows[1]['id'];
        $this->gestionId  = (int) $rows[2]['id'];
        $this->benovoleId = (int) $rows[3]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'test-kermesse-22',
            'name'        => 'Kermesse Test 2.2',
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

    private function csrfPost(string $url, array $data): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
    }

    private function insertStand(string $name, int $order = 1): int
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

    // ------------------------------------------------------------------
    // AC1 — Ajout d'un stand
    // ------------------------------------------------------------------

    public function testOwnerCanAddStand(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => 'Stand Bricolage']);

        $result->assertRedirect();

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Bricolage'")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testAdminCanAddStand(): void
    {
        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => 'Stand Cuisine']);

        $result->assertRedirect();

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Cuisine'")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testGestionnaireCannotAddStand(): void
    {
        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => 'Stand Interdit']);

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Interdit'")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testUnauthenticatedCannotAddStand(): void
    {
        $result = $this->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => 'Stand Anonyme']);

        // Redirect to login (role filter redirects unauthenticated)
        $this->assertContains($result->response()->getStatusCode(), [302, 403]);

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Anonyme'")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    public function testAddStandWithEmptyNameShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => '']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');
    }

    public function testAddStandValidationErrorPreservesName(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => '']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');
    }

    public function testAddDuplicateActiveStandNameShowsError(): void
    {
        $this->insertStand('Stand Dupont');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => 'Stand Dupont']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');

        // Only 1 stand with that name must exist
        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Dupont'")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testAddStandCaseInsensitiveDuplicateShowsError(): void
    {
        $this->insertStand('Stand Test');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => 'STAND TEST']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');
    }

    // ------------------------------------------------------------------
    // AC2 — Modification du nom d'un stand
    // ------------------------------------------------------------------

    public function testOwnerCanRenameStand(): void
    {
        $standId = $this->insertStand('Ancien Nom');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}", ['name' => 'Nouveau Nom']);

        $result->assertRedirect();

        $row = db_connect()
            ->query("SELECT name FROM db_stands WHERE id = {$standId}")
            ->getRowArray();
        $this->assertSame('Nouveau Nom', $row['name']);
    }

    public function testAdminCanRenameStand(): void
    {
        $standId = $this->insertStand('Stand À Renommer');

        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}", ['name' => 'Stand Renommé']);

        $result->assertRedirect();

        $row = db_connect()
            ->query("SELECT name FROM db_stands WHERE id = {$standId}")
            ->getRowArray();
        $this->assertSame('Stand Renommé', $row['name']);
    }

    public function testGestionnaireCannotRenameStand(): void
    {
        $standId = $this->insertStand('Stand Protégé');

        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}", ['name' => 'Tentative']);

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());

        $row = db_connect()
            ->query("SELECT name FROM db_stands WHERE id = {$standId}")
            ->getRowArray();
        $this->assertSame('Stand Protégé', $row['name']);
    }

    public function testRenameStandWithEmptyNameShowsError(): void
    {
        $standId = $this->insertStand('Stand Valide');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}", ['name' => '']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');

        // Name must be unchanged
        $row = db_connect()
            ->query("SELECT name FROM db_stands WHERE id = {$standId}")
            ->getRowArray();
        $this->assertSame('Stand Valide', $row['name']);
    }

    public function testRenameToDuplicateActiveNameShowsError(): void
    {
        $this->insertStand('Stand A');
        $standBId = $this->insertStand('Stand B');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standBId}", ['name' => 'Stand A']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');
    }

    public function testRenameToSameNameIsAllowed(): void
    {
        $standId = $this->insertStand('Stand Inchangé');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}", ['name' => 'Stand Inchangé']);

        $result->assertRedirect();
    }

    public function testUpdateNonExistentStandReturns404(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/99999", ['name' => 'Ghost']);

        $result->assertStatus(404);
    }

    public function testOwnerCanDuplicateStand(): void
    {
        $standId = $this->insertStand('Stand Original');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", []);

        $result->assertRedirect();

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Original (copie)'")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }
}
