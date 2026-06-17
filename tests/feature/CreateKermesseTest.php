<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 2.1 — Créer une nouvelle kermesse.
 *
 * Couvre :
 * - Accès à la page de création (connecté / non connecté)
 * - Succès de la création par un utilisateur connecté (redirection directe)
 * - Succès de la création par un visiteur non connecté (magic link envoyé)
 * - Erreurs de validation avec conservation des valeurs saisies
 *
 * @internal
 */
final class CreateKermesseTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $testUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->testUserId = $this->insertUser('creator@kermesse-test.com');
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_access_tokens');
        $db->query('DELETE FROM db_email_events');
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
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id    INTEGER NOT NULL,
                name           TEXT    NOT NULL,
                display_order  INTEGER NOT NULL DEFAULT 0,
                status         TEXT    NOT NULL DEFAULT \'active\',
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_stands_active_name ON db_stands (kermesse_id, name) WHERE status = "active"');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_access_tokens (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash  TEXT NOT NULL UNIQUE,
                token_type  TEXT NOT NULL,
                user_id     INTEGER,
                owner_id    INTEGER,
                kermesse_id INTEGER,
                email       TEXT,
                expires_at  DATETIME NOT NULL,
                used_at     DATETIME,
                revoked_at  DATETIME,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_email_events (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type      TEXT NOT NULL,
                status          TEXT NOT NULL DEFAULT "sent",
                recipient_email TEXT NOT NULL,
                recipient_hash  TEXT NOT NULL,
                error_message   TEXT,
                metadata        TEXT,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
            CREATE TABLE IF NOT EXISTS db_signups (
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

    private function insertUser(string $email, string $firstName = 'Test', string $lastName = 'User'): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => '',
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string, mixed> */
    private function connectedSession(): array
    {
        return ['user_id' => $this->testUserId, 'is_logged_in' => true];
    }

    private function csrfPost(string $url, array $data): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
    }

    /** @return array<string, string> */
    private function validKermesseData(): array
    {
        return [
            'name'              => 'Kermesse de l\'École',
            'event_date'        => '2026-09-15',
            'location'          => 'Salle des fêtes',
            'short_description' => 'Une super kermesse !',
        ];
    }

    // ------------------------------------------------------------------
    // AC — Accès à la page de création
    // ------------------------------------------------------------------

    public function testGetCreatePageReturns200ForGuest(): void
    {
        $result = $this->get('kermesse/create');

        $result->assertStatus(200);
        $result->assertSee('Créer une kermesse');
    }

    public function testGetCreatePageReturns200ForConnectedUser(): void
    {
        $result = $this->withSession($this->connectedSession())->get('kermesse/create');

        $result->assertStatus(200);
        $result->assertSee('Créer une kermesse');
    }

    public function testGetCreatePageForGuestShowsOrganizerFields(): void
    {
        $result = $this->get('kermesse/create');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('name="first_name"', $body, 'Guest form must show first_name field');
        $this->assertStringContainsString('name="last_name"', $body, 'Guest form must show last_name field');
        $this->assertStringContainsString('name="email"', $body, 'Guest form must show email field');
    }

    public function testGetCreatePageForConnectedUserHidesOrganizerFields(): void
    {
        $result = $this->withSession($this->connectedSession())->get('kermesse/create');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('name="first_name"', $body, 'Connected form must not show first_name field');
        $this->assertStringNotContainsString('name="last_name"', $body, 'Connected form must not show last_name field');
    }

    public function testCreateFormContainsCsrfField(): void
    {
        $result = $this->get('kermesse/create');
        $body   = $result->response()->getBody();

        $this->assertMatchesRegularExpression('/name="csrf_test_name"/', $body);
    }

    // ------------------------------------------------------------------
    // AC2 — Flux connecté : création directe + redirection
    // ------------------------------------------------------------------

    public function testConnectedUserCreatesKermesseAndIsRedirected(): void
    {
        $result = $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $this->validKermesseData());

        $result->assertRedirect();
        $this->assertStringContainsString('kermesse/', $result->getRedirectUrl());
    }

    public function testConnectedUserCreationInsertsKermesseInDb(): void
    {
        $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $this->validKermesseData());

        $db  = db_connect();
        $row = $db->query(
            "SELECT name, status, created_by FROM db_kermesses WHERE name = 'Kermesse de l''École'"
        )->getRowArray();

        $this->assertNotNull($row, 'Kermesse must be inserted in database');
        $this->assertSame('preparation', $row['status']);
        $this->assertSame((string) $this->testUserId, (string) $row['created_by']);
    }

    public function testConnectedUserCreationAssignsOwnerRole(): void
    {
        $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $this->validKermesseData());

        $db = db_connect();

        $kermesse = $db->query(
            "SELECT id FROM db_kermesses WHERE name = 'Kermesse de l''École'"
        )->getRowArray();
        $this->assertNotNull($kermesse, 'Kermesse must exist in DB');

        $role = $db->query(
            "SELECT role FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?",
            [(int) $kermesse['id'], $this->testUserId]
        )->getRowArray();

        $this->assertNotNull($role, 'Owner role must be assigned');
        $this->assertSame('owner', $role['role']);
    }

    public function testStaleConnectedSessionFallsBackToGuestValidation(): void
    {
        $result = $this->withSession(['user_id' => 999999, 'is_logged_in' => true])
            ->csrfPost('kermesses', $this->validKermesseData());

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('form-error', $body, 'A stale session must not create an orphaned kermesse');

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_kermesses WHERE name = 'Kermesse de l''École'")
            ->getRowArray()['cnt'];
        $this->assertSame(0, $count);
    }

    // ------------------------------------------------------------------
    // AC1 — Flux non connecté : user + kermesse + rôle + magic link
    // ------------------------------------------------------------------

    public function testGuestCreatesKermesseAndSeesMagicLinkSentView(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@example.com',
        ]);

        $result = $this->csrfPost('kermesses', $data);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('boîte mail', $body, 'Guest must see the magic link sent confirmation');
    }

    public function testGuestCreationCreatesUserInDb(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Jean',
            'last_name'  => 'Martin',
            'email'      => 'jean@example.com',
        ]);

        $this->csrfPost('kermesses', $data);

        $db  = db_connect();
        $row = $db->query(
            "SELECT first_name, last_name FROM db_users WHERE email = 'jean@example.com'"
        )->getRowArray();

        $this->assertNotNull($row, 'User must be created in database for guest');
        $this->assertSame('Jean', $row['first_name']);
        $this->assertSame('Martin', $row['last_name']);
    }

    public function testGuestCreationCreatesMagicLinkToken(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Sophie',
            'last_name'  => 'Bernard',
            'email'      => 'sophie@example.com',
        ]);

        $this->csrfPost('kermesses', $data);

        $db  = db_connect();
        $row = $db->query(
            "SELECT token_type, email FROM db_access_tokens WHERE email = 'sophie@example.com'"
        )->getRowArray();

        $this->assertNotNull($row, 'A magic_link token must be issued for the guest');
        $this->assertSame('magic_link', $row['token_type']);
    }

    public function testGuestCreationStoresKermesseIntentOnMagicLinkToken(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Nina',
            'last_name'  => 'Roux',
            'email'      => 'nina@example.com',
        ]);

        $this->csrfPost('kermesses', $data);

        $db       = db_connect();
        $kermesse = $db->query(
            "SELECT id FROM db_kermesses WHERE name = 'Kermesse de l''École'"
        )->getRowArray();
        $token = $db->query(
            "SELECT kermesse_id FROM db_access_tokens WHERE email = 'nina@example.com'"
        )->getRowArray();

        $this->assertNotNull($kermesse);
        $this->assertNotNull($token);
        $this->assertSame((string) $kermesse['id'], (string) $token['kermesse_id']);
    }

    public function testGuestCreationRecordsEmailEvent(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Pierre',
            'last_name'  => 'Leblanc',
            'email'      => 'pierre@example.com',
        ]);

        $this->csrfPost('kermesses', $data);

        $db  = db_connect();
        $row = $db->query(
            "SELECT event_type FROM db_email_events WHERE recipient_email = 'pierre@example.com'"
        )->getRowArray();

        $this->assertNotNull($row, 'An email_event must be recorded for the magic link');
        $this->assertSame('magic_link', $row['event_type']);
    }

    public function testGuestCreationAssignsOwnerRole(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Lucie',
            'last_name'  => 'Morel',
            'email'      => 'lucie@example.com',
        ]);

        $this->csrfPost('kermesses', $data);

        $db = db_connect();

        $user = $db->query(
            "SELECT id FROM db_users WHERE email = 'lucie@example.com'"
        )->getRowArray();
        $this->assertNotNull($user, 'User must exist');

        $kermesse = $db->query(
            "SELECT id FROM db_kermesses WHERE created_by = ?",
            [(int) $user['id']]
        )->getRowArray();
        $this->assertNotNull($kermesse, 'Kermesse must be created');

        $role = $db->query(
            "SELECT role FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?",
            [(int) $kermesse['id'], (int) $user['id']]
        )->getRowArray();

        $this->assertNotNull($role, 'Owner role must be assigned to guest user');
        $this->assertSame('owner', $role['role']);
    }

    public function testGuestWithExistingEmailReusesThatUser(): void
    {
        // Pre-create a user with the same email
        $existingId = $this->insertUser('existing@example.com', 'Existant', 'Déjà');

        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Nouveau',
            'last_name'  => 'Nom',
            'email'      => 'existing@example.com',
        ]);

        $this->csrfPost('kermesses', $data);

        $db    = db_connect();
        $count = (int) $db->query(
            "SELECT COUNT(*) AS cnt FROM db_users WHERE email = 'existing@example.com'"
        )->getRowArray()['cnt'];

        $this->assertSame(1, $count, 'Must not create a duplicate user for an existing email');

        $kermesse = $db->query(
            "SELECT created_by FROM db_kermesses"
        )->getRowArray();
        $this->assertNotNull($kermesse);
        $this->assertSame((string) $existingId, (string) $kermesse['created_by'], 'Kermesse must be owned by the existing user');
    }

    // ------------------------------------------------------------------
    // Erreurs de validation — conservation des valeurs saisies
    // ------------------------------------------------------------------

    public function testConnectedUserMissingNameShowsValidationError(): void
    {
        $data = $this->validKermesseData();
        unset($data['name']);

        $result = $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $data);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('form-error', $body, 'Validation error must be shown when name is missing');
    }

    public function testConnectedUserMissingLocationShowsValidationError(): void
    {
        $data             = $this->validKermesseData();
        $data['location'] = '';

        $result = $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $data);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('form-error', $body);
    }

    public function testValidationErrorPreservesEnteredValues(): void
    {
        $data             = $this->validKermesseData();
        $data['name']     = '';
        $data['location'] = 'SalleDuTest123';

        $result = $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $data);

        $body = $result->response()->getBody();
        // Use ASCII-only value: esc(...,'attr') via Laminas encodes accents and spaces
        $this->assertStringContainsString('SalleDuTest123', $body, 'Previously entered location must be preserved on error (UX-DR27)');
    }

    public function testGuestMissingEmailShowsValidationError(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Test',
            'last_name'  => 'Guest',
        ]);

        $result = $this->csrfPost('kermesses', $data);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('form-error', $body);
    }

    public function testGuestInvalidEmailShowsValidationError(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'first_name' => 'Test',
            'last_name'  => 'Guest',
            'email'      => 'not-an-email',
        ]);

        $result = $this->csrfPost('kermesses', $data);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('form-error', $body);
    }

    public function testGuestValidationErrorPreservesEnteredName(): void
    {
        $data = array_merge($this->validKermesseData(), [
            'name'       => 'KermessePrintemps2026',
            'first_name' => 'Andre',
            'last_name'  => 'Fontaine',
            'email'      => 'bad@@email',
        ]);

        $result = $this->csrfPost('kermesses', $data);

        $body = $result->response()->getBody();
        // Use ASCII-only value: esc(...,'attr') via Laminas encodes accents and spaces
        $this->assertStringContainsString('KermessePrintemps2026', $body, 'Kermesse name must be preserved on guest validation error');
    }

    public function testArrayShapedPostFieldsShowValidationErrors(): void
    {
        $data = $this->validKermesseData();
        $data['name'] = ['unexpected'];

        $result = $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $data);

        $result->assertStatus(200);
        $this->assertStringContainsString('form-error', $result->response()->getBody());
    }

    // ------------------------------------------------------------------
    // Dashboard kermesse (AC2 — route /kermesse/{id})
    // ------------------------------------------------------------------

    public function testConnectedUserRedirectsToKermesseDashboard(): void
    {
        $result = $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $this->validKermesseData());

        $db       = db_connect();
        $kermesse = $db->query("SELECT id FROM db_kermesses LIMIT 1")->getRowArray();
        $this->assertNotNull($kermesse);

        $dashboard = $this->withSession($this->connectedSession())
            ->get('kermesse/' . (int) $kermesse['id']);

        $dashboard->assertStatus(200);
        $dashboard->assertSee("Kermesse de l'École");
    }

    public function testNonOwnerCannotAccessAnotherKermesseDashboard(): void
    {
        $this->withSession($this->connectedSession())
            ->csrfPost('kermesses', $this->validKermesseData());

        $db       = db_connect();
        $kermesse = $db->query("SELECT id FROM db_kermesses LIMIT 1")->getRowArray();
        $otherId  = $this->insertUser('other@kermesse-test.com');

        $dashboard = $this->withSession(['user_id' => $otherId, 'is_logged_in' => true])
            ->get('kermesse/' . (int) $kermesse['id']);

        $dashboard->assertStatus(403);
    }
}
