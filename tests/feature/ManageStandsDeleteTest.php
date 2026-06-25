<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 2.4 — Supprimer un stand avec sécurité destructive.
 *
 * Couvre :
 * - AC1 : Suppression simple (stand sans inscriptions actives) → désactivation du stand
 * - AC2 : Suppression forte (SUPPRIMER obligatoire si inscriptions actives) → signups rendus inactifs
 * - Soft-delete : le stand passe en 'deactivated', rien n'est physiquement supprimé
 * - Autorisation : Owner/Admin uniquement
 *
 * @internal
 */
final class ManageStandsDeleteTest extends CIUnitTestCase
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
        $db->query('DELETE FROM db_slot_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Setup helpers
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
            ['owner@delete-test.com', 'Owner', 'Test'],
            ['admin@delete-test.com', 'Admin', 'Test'],
            ['gestion@delete-test.com', 'Gestion', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '', 
            ]);
        }

        $rows = $db->query("SELECT id FROM db_users ORDER BY id ASC")->getResultArray();
        $this->ownerId   = (int) $rows[0]['id'];
        $this->adminId   = (int) $rows[1]['id'];
        $this->gestionId = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'test-kermesse-24',
            'name'        => 'Kermesse Test 2.4',
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

    private function insertStand(string $name): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => $name,
            'display_order' => 1,
            'status'        => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSlot(int $standId): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-06-15 09:00:00',
            'ends_at'   => '2026-06-15 11:00:00',
            'capacity'  => 5,
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

    private function standStatus(int $standId): ?string
    {
        $row = db_connect()
            ->query("SELECT status FROM db_stands WHERE id = {$standId}")
            ->getRowArray();
        return $row['status'] ?? null;
    }

    private function slotsExistForStand(int $standId): bool
    {
        $row = db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slots WHERE stand_id = {$standId}")
            ->getRowArray();
        return (int) $row['cnt'] > 0;
    }

    private function activeSignupsExistForSlot(int $slotId): bool
    {
        $row = db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_slot_signups WHERE slot_id = {$slotId} AND status = 'active' AND deleted_at IS NULL")
            ->getRowArray();
        return (int) $row['cnt'] > 0;
    }

    // ------------------------------------------------------------------
    // AC1 — Suppression simple (pas d'inscriptions)
    // ------------------------------------------------------------------

    public function testOwnerCanDeleteStandWithoutSignups(): void
    {
        $standId = $this->insertStand('Stand Bricolage');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", []);

        $result->assertRedirect();
        $this->assertSame('deactivated', $this->standStatus($standId));
    }

    public function testAdminCanDeleteStandWithoutSignups(): void
    {
        $standId = $this->insertStand('Stand Cuisine');

        $result = $this->withSession($this->session($this->adminId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", []);

        $result->assertRedirect();
        $this->assertSame('deactivated', $this->standStatus($standId));
    }

    public function testGestionnaireCannotDeleteStand(): void
    {
        $standId = $this->insertStand('Stand Protégé');

        $result = $this->withSession($this->session($this->gestionId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", []);

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());
        $this->assertSame('active', $this->standStatus($standId));
    }

    public function testUnauthenticatedCannotDeleteStand(): void
    {
        $standId = $this->insertStand('Stand Anonyme');

        $result = $this->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", []);

        $this->assertContains($result->response()->getStatusCode(), [302, 403]);
        $this->assertSame('active', $this->standStatus($standId));
    }

    public function testDeleteNonExistentStandReturns404(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/99999/delete", []);

        $result->assertStatus(404);
    }

    public function testDeleteFlashSuccessMessage(): void
    {
        $standId = $this->insertStand('Stand Flash');

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", []);

        $result->assertRedirect();
        $result->assertSessionHas('success');
    }

    // ------------------------------------------------------------------
    // AC1 — Soft-delete : le stand disparaît des vues actives, rien n'est détruit
    // ------------------------------------------------------------------

    public function testDeleteStandDeactivatesAndHidesFromActiveList(): void
    {
        $standId = $this->insertStand('Stand Avec Créneaux');
        $this->insertSlot($standId);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", []);

        $result->assertRedirect();

        // Stand désactivé (soft-delete), donc absent de la liste active
        $this->assertSame('deactivated', $this->standStatus($standId));
        $activeIds = array_column(
            model(\App\Models\StandModel::class)->getActiveForKermesse($this->kermesseId),
            'id',
        );
        $this->assertNotContains($standId, array_map('intval', $activeIds));

        // Les créneaux ne sont pas détruits : ils restent comme ancrage de l'historique
        $this->assertTrue($this->slotsExistForStand($standId));
    }

    // ------------------------------------------------------------------
    // AC2 — Suppression forte (SUPPRIMER requis si inscriptions actives)
    // ------------------------------------------------------------------

    public function testDeleteStandWithActiveSignupsWithoutConfirmShowsError(): void
    {
        $standId  = $this->insertStand('Stand Inscriptions');
        $slotId   = $this->insertSlot($standId);
        $this->insertSignup($slotId, $this->ownerId);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", ['confirm' => '']);

        $result->assertStatus(302);
        $result->assertSessionHas('delete_error_' . $standId);
        // Rien ne doit changer : stand actif, inscription toujours active
        $this->assertSame('active', $this->standStatus($standId));
        $this->assertTrue($this->activeSignupsExistForSlot($slotId));
    }

    public function testDeleteStandWithActiveSignupsWithWrongConfirmShowsError(): void
    {
        $standId  = $this->insertStand('Stand Wrong Confirm');
        $slotId   = $this->insertSlot($standId);
        $this->insertSignup($slotId, $this->ownerId);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", ['confirm' => 'EFFACER']);

        $result->assertStatus(302);
        $result->assertSessionHas('delete_error_' . $standId);
        $this->assertSame('active', $this->standStatus($standId));
        $this->assertTrue($this->activeSignupsExistForSlot($slotId));
    }

    public function testDeleteStandWithLowercaseConfirmIsAcceptedCaseInsensitive(): void
    {
        // Le reviewer a rendu la confirmation insensible à la casse (serveur + JS) :
        // « supprimer » est accepté comme « SUPPRIMER ».
        $standId = $this->insertStand('Stand Casse');
        $slotId  = $this->insertSlot($standId);
        $this->insertSignup($slotId, $this->ownerId);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", ['confirm' => 'supprimer']);

        $result->assertRedirect();
        $this->assertSame('deactivated', $this->standStatus($standId));
        $this->assertFalse($this->activeSignupsExistForSlot($slotId));
    }

    public function testDeleteStandWithActiveSignupsWithCorrectConfirmSucceeds(): void
    {
        $standId = $this->insertStand('Stand SUPPRIMER');
        $slotId  = $this->insertSlot($standId);
        $this->insertSignup($slotId, $this->ownerId);

        $result = $this->withSession($this->session($this->ownerId))
            ->csrfPost("kermesse/{$this->kermesseId}/stands/{$standId}/delete", ['confirm' => 'SUPPRIMER']);

        $result->assertRedirect();
        // Stand désactivé et inscriptions rendues inactives (AC2), sans destruction physique
        $this->assertSame('deactivated', $this->standStatus($standId));
        $this->assertFalse($this->activeSignupsExistForSlot($slotId));
        $this->assertTrue($this->slotsExistForStand($standId));
    }
}
