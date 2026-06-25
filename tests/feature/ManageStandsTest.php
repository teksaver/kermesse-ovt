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
        $db->query('DELETE FROM db_slot_signups');
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
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_stands_active_name ON db_stands (kermesse_id, name) WHERE status = "active"');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slots (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                stand_id   INTEGER NOT NULL,
                starts_at  DATETIME NOT NULL,
                ends_at    DATETIME NOT NULL,
                capacity   INTEGER  NOT NULL DEFAULT 1,
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
                status                    TEXT     NOT NULL DEFAULT \'active\',
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

    private function insertSlot(int $standId, string $startsAt, string $endsAt, int $capacity): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'capacity'  => $capacity,
            'status'    => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, int $userId): int
    {
        $db = db_connect();
        $db->table('slot_signups')->insert([
            'slot_id' => $slotId,
            'user_id' => $userId,
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

    // ------------------------------------------------------------------
    // Story 5.6 — Duplication d'un stand avec nom choisi
    // ------------------------------------------------------------------

    public function testOwnerCanDuplicateStandWithProvidedName(): void
    {
        $standId = $this->insertStand('Stand Original');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => 'Stand Bis']);

        $result->assertRedirect();

        $db = db_connect();

        // Le nom fourni est utilisé tel quel...
        $count = (int) $db
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE kermesse_id = {$this->kermesseId} AND name = 'Stand Bis'")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);

        // ...et l'ancien nom auto-généré « (copie) » n'est plus créé.
        $autoCount = (int) $db
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE name = 'Stand Original (copie)'")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $autoCount);
    }

    public function testDuplicateCopiesAllActiveSlots(): void
    {
        $standId = $this->insertStand('Stand Source');
        $this->insertSlot($standId, '2026-07-01 09:00:00', '2026-07-01 10:00:00', 3);
        $this->insertSlot($standId, '2026-07-01 10:00:00', '2026-07-01 11:00:00', 5);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => 'Stand Cloné']);

        $result->assertRedirect();

        $db          = db_connect();
        $newStandId  = (int) $db->query("SELECT id FROM db_stands WHERE name = 'Stand Cloné'")->getRowArray()['id'];
        $copiedSlots = $db->query("SELECT starts_at, ends_at, capacity, status FROM db_slots WHERE stand_id = {$newStandId} ORDER BY starts_at ASC")->getResultArray();

        $this->assertCount(2, $copiedSlots);
        $this->assertSame('2026-07-01 09:00:00', $copiedSlots[0]['starts_at']);
        $this->assertSame('2026-07-01 10:00:00', $copiedSlots[0]['ends_at']);
        $this->assertSame(3, (int) $copiedSlots[0]['capacity']);
        $this->assertSame('active', $copiedSlots[0]['status']);
        $this->assertSame(5, (int) $copiedSlots[1]['capacity']);
    }

    public function testDuplicateDoesNotCopySignups(): void
    {
        $standId = $this->insertStand('Stand Inscrit');
        $slotId  = $this->insertSlot($standId, '2026-07-01 09:00:00', '2026-07-01 10:00:00', 3);
        $this->insertSignup($slotId, $this->benovoleId);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => 'Stand Vierge']);

        $result->assertRedirect();

        $db         = db_connect();
        $newStandId = (int) $db->query("SELECT id FROM db_stands WHERE name = 'Stand Vierge'")->getRowArray()['id'];

        // Le nouveau stand part avec zéro inscrit : aucun signup ne pointe vers ses créneaux.
        $signupCount = (int) $db
            ->query("SELECT COUNT(*) AS cnt FROM db_slot_signups s JOIN db_slots sl ON sl.id = s.slot_id WHERE sl.stand_id = {$newStandId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $signupCount);

        // L'inscription d'origine reste intacte.
        $totalSignups = (int) $db->query('SELECT COUNT(*) AS cnt FROM db_slot_signups')->getRowArray()['cnt'];
        $this->assertSame(1, $totalSignups);
    }

    public function testDuplicateStandWithoutSlotsCreatesEmptyStand(): void
    {
        $standId = $this->insertStand('Stand Vide');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => 'Stand Vide Copie']);

        $result->assertRedirect();

        $db         = db_connect();
        $newStandId = (int) $db->query("SELECT id FROM db_stands WHERE name = 'Stand Vide Copie'")->getRowArray()['id'];
        $this->assertGreaterThan(0, $newStandId);

        $slotCount = (int) $db->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$newStandId}")->getRowArray()['cnt'];
        $this->assertSame(0, $slotCount);
    }

    public function testDuplicateWithEmptyNameShowsError(): void
    {
        $standId = $this->insertStand('Stand Sans Nom Cible');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => '   ']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');

        // Aucun stand supplémentaire n'a été créé.
        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE kermesse_id = {$this->kermesseId}")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testDuplicateWithExistingActiveNameShowsError(): void
    {
        $standId = $this->insertStand('Stand A', 1);
        $this->insertStand('Stand B', 2);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => 'Stand B']);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');

        // Toujours seulement les 2 stands d'origine.
        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE kermesse_id = {$this->kermesseId}")
            ->getRowArray()['cnt'];
        $this->assertSame(2, $count);
    }

    public function testDuplicateNonExistentStandReturns404(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/99999/duplicate", ['name' => 'Fantôme']);

        $result->assertStatus(404);
    }

    public function testDuplicateWithTooLongNameShowsError(): void
    {
        $standId = $this->insertStand('Stand Source Borne');

        // 256 caractères : dépasse la colonne stands.name VARCHAR(255).
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/duplicate", ['name' => str_repeat('a', 256)]);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE kermesse_id = {$this->kermesseId}")
            ->getRowArray()['cnt'];
        $this->assertSame(1, $count);
    }

    public function testAddStandWithTooLongNameShowsError(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands", ['name' => str_repeat('a', 256)]);

        $result->assertStatus(302);
        $result->assertSessionHas('stand_error');

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_stands WHERE kermesse_id = {$this->kermesseId}")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }
}
