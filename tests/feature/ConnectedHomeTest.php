<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 1.5 — Accueil connecté : liste de kermesses et déconnexion.
 *
 * Couvre :
 * - Affichage conditionnel de / (public vs connecté)
 * - État vide vs liste de kermesses avec rôle
 * - Déconnexion POST /auth/logout
 *
 * @internal
 */
final class ConnectedHomeTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private int $testUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->testUserId = $this->insertUser('user@kermesse-test.com');
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
                email_hash TEXT    NOT NULL,
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
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
        // Story 5.9: HomeController::index() calls canLeaveKermesse() which queries signups.
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
                capacity   INTEGER NOT NULL DEFAULT 1,
                status     TEXT    NOT NULL DEFAULT \'active\',
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

    private function insertUser(string $email): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => 'Test',
            'last_name'  => 'User',
            'phone'      => '', 
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string, mixed> */
    private function connectedSession(): array
    {
        return ['user_id' => $this->testUserId, 'is_logged_in' => true];
    }

    private function csrfPost(string $url, array $data = []): mixed
    {
        $security                         = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
    }

    private function insertKermesse(string $name, string $slug, int $ownerId, string $status = 'preparation'): int
    {
        $db = db_connect();
        $db->table('kermesses')->insert([
            'created_by'  => $ownerId,
            'public_slug' => $slug,
            'name'        => $name,
            'status'      => $status,
        ]);

        return (int) $db->insertID();
    }

    private function assignRole(int $kermesseId, int $userId, string $role): void
    {
        db_connect()->table('kermesse_user_roles')->insert([
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => $role,
        ]);
    }

    // ------------------------------------------------------------------
    // AC1 — Affichage conditionnel de /
    // ------------------------------------------------------------------

    public function testPublicUserAtRootSeesPublicHome(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
        $result->assertSee('Me connecter');
        $result->assertSee('Créer une kermesse');
    }

    public function testConnectedUserAtRootSeesConnectedHome(): void
    {
        $result = $this->withSession($this->connectedSession())->get('/');

        $result->assertStatus(200);
        $result->assertSee('Mes kermesses');
    }

    public function testConnectedUserAtRootDoesNotSeePublicLoginCta(): void
    {
        $result = $this->withSession($this->connectedSession())->get('/');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('Me connecter', $body,
            'Connected home must not show the public "Me connecter" CTA');
    }

    public function testConnectedUserWithNoKermessesSeesEmptyState(): void
    {
        $result = $this->withSession($this->connectedSession())->get('/');

        $result->assertStatus(200);
        $result->assertSee('aucune kermesse');
    }

    public function testConnectedUserSeesCreateKermesseButton(): void
    {
        $result = $this->withSession($this->connectedSession())->get('/');

        $result->assertSee('Créer une nouvelle kermesse');
    }

    public function testConnectedUserWithKermessesSeesTheirName(): void
    {
        $kId = $this->insertKermesse('Kermesse de Printemps', 'kermesse-printemps', $this->testUserId);
        $this->assignRole($kId, $this->testUserId, 'owner');

        $result = $this->withSession($this->connectedSession())->get('/');

        $result->assertStatus(200);
        $result->assertSee('Kermesse de Printemps');
    }

    public function testConnectedUserSeesRoleLabelForOwnedKermesse(): void
    {
        $kId = $this->insertKermesse('Fête de l\'École', 'fete-ecole', $this->testUserId);
        $this->assignRole($kId, $this->testUserId, 'owner');

        $result = $this->withSession($this->connectedSession())->get('/');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Organisateur', $body,
            'Owner role must be displayed as "Organisateur"');
    }

    public function testConnectedUserSeesGestionnaireLabel(): void
    {
        $ownerId = $this->insertUser('owner@example.com');
        $kId     = $this->insertKermesse('Fête de Village', 'fete-village', $ownerId);
        $this->assignRole($kId, $this->testUserId, 'gestionnaire');

        $result = $this->withSession($this->connectedSession())->get('/');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Gestionnaire', $body,
            'Gestionnaire role must be displayed as "Gestionnaire"');
    }

    public function testConnectedUserSeesKermesseStatusLabel(): void
    {
        $kId = $this->insertKermesse('Kermesse ouverte', 'kermesse-ouverte', $this->testUserId, 'open');
        $this->assignRole($kId, $this->testUserId, 'owner');

        $result = $this->withSession($this->connectedSession())->get('/');
        $body   = $result->response()->getBody();

        $result->assertStatus(200);
        $this->assertStringContainsString('Inscriptions ouvertes', $body);
        $this->assertStringContainsString('kermesse-status-badge--open', $body);
    }

    public function testConnectedHomeHasLogoutButton(): void
    {
        $result = $this->withSession($this->connectedSession())->get('/');

        $result->assertSee('Se déconnecter');
    }

    public function testPublicHomeHasNoLogoutButton(): void
    {
        $result = $this->get('/');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('Se déconnecter', $body,
            'Public home must not show a logout button');
    }

    // ------------------------------------------------------------------
    // AC2 — Déconnexion POST /auth/logout
    // ------------------------------------------------------------------

    public function testLogoutRedirectsToRoot(): void
    {
        $result = $this->withSession($this->connectedSession())->csrfPost('auth/logout');

        $result->assertRedirectTo(site_url('/'));
    }

    public function testLogoutWithoutSessionStillRedirectsToRoot(): void
    {
        $result = $this->csrfPost('auth/logout');

        $result->assertRedirectTo(site_url('/'));
    }
}
