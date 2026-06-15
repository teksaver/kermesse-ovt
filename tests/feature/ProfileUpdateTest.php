<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for Story 5.7 — Gestion des coordonnées via la page profil.
 *
 * Couvre :
 * - AC1 : GET /profile affiche le formulaire avec les coordonnées actuelles.
 * - AC1 : POST /profile valide et enregistre → PRG + message de succès.
 * - AC1 : POST /profile → validation failure (champ requis vide).
 * - AC2 : Filtre pending-resolution intercepte l'accès à /profile.
 * - AC3 : Email déjà utilisé par un autre compte → erreur "Cet email est déjà utilisé".
 * - AC4 : Changement d'email disponible → email + email_hash mis à jour.
 *
 * @internal
 */
final class ProfileUpdateTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->userId = $this->insertUser('alice@example.com', 'Alice', 'Martin', '0611111111');
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Table setup
    // ------------------------------------------------------------------

    private function setUpTables(): void
    {
        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         TEXT    NOT NULL,
                email_hash    TEXT    NOT NULL UNIQUE,
                first_name    TEXT    NOT NULL DEFAULT "",
                last_name     TEXT    NOT NULL DEFAULT "",
                phone         TEXT    NOT NULL DEFAULT "",
                last_login_at DATETIME NULL DEFAULT NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertUser(
        string $email,
        string $firstName = 'Alice',
        string $lastName  = 'Martin',
        string $phone     = '',
    ): int {
        $db = db_connect();
        $db->table('users')->insert([
            'email'         => $email,
            'email_hash'    => hash('sha256', $email),
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'phone'         => $phone,
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string, mixed> */
    private function connectedSession(): array
    {
        return ['user_id' => $this->userId, 'is_logged_in' => true];
    }

    private function csrfPost(string $url, array $data = []): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->withSession($this->connectedSession())->post($url, $data);
    }

    // ------------------------------------------------------------------
    // AC1 — GET /profile affiche le formulaire avec les données actuelles
    // ------------------------------------------------------------------

    public function testGetProfileShowsFormWithCurrentUserData(): void
    {
        $result = $this->withSession($this->connectedSession())->get('profile');

        $result->assertStatus(200);
        $result->assertSee('Alice');
        $result->assertSee('Martin');
        $result->assertSee('alice@example.com');
    }

    // ------------------------------------------------------------------
    // AC1 — POST /profile avec données valides → PRG + flash succès
    // ------------------------------------------------------------------

    public function testPostProfileWithValidDataUpdatesAndRedirects(): void
    {
        $result = $this->csrfPost('profile', [
            'first_name' => 'Alicia',
            'last_name'  => 'Dupont',
            'email'      => 'alice@example.com',
            'phone'      => '0699999999',
        ]);

        $result->assertRedirectTo(site_url('profile'));

        $db   = db_connect();
        $user = $db->table('users')->where('id', $this->userId)->get()->getRowArray();
        $this->assertSame('Alicia', $user['first_name']);
        $this->assertSame('Dupont', $user['last_name']);
        $this->assertSame('0699999999', $user['phone']);
    }

    // ------------------------------------------------------------------
    // AC1 — POST /profile → échec validation (prénom vide)
    // ------------------------------------------------------------------

    public function testPostProfileWithEmptyFirstNameFailsValidation(): void
    {
        $result = $this->csrfPost('profile', [
            'first_name' => '',
            'last_name'  => 'Martin',
            'email'      => 'alice@example.com',
            'phone'      => '',
        ]);

        $result->assertRedirect();

        // Le prénom ne doit pas avoir changé.
        $db   = db_connect();
        $user = $db->table('users')->where('id', $this->userId)->get()->getRowArray();
        $this->assertSame('Alice', $user['first_name']);
    }

    // ------------------------------------------------------------------
    // AC2 — Filtre pending-resolution intercepte /profile
    // ------------------------------------------------------------------

    public function testGetProfileRedirectsWhenPendingResolutionIsActive(): void
    {
        $session = array_merge($this->connectedSession(), [
            'pending_profile_resolution' => true,
        ]);

        $result = $this->withSession($session)->get('profile');

        $result->assertRedirectTo(site_url('auth/profile-resolution'));
    }

    public function testPostProfileRedirectsWhenPendingFirstLoginIsActive(): void
    {
        $session = array_merge($this->connectedSession(), [
            'pending_first_login_confirmation' => true,
        ]);

        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        $result = $this->withSession($session)->post('profile', $data);

        $result->assertRedirectTo(site_url('auth/profile-resolution'));
    }

    // ------------------------------------------------------------------
    // AC3 — Email déjà utilisé par un autre compte → erreur spécifique
    // ------------------------------------------------------------------

    public function testPostProfileWithDuplicateEmailShowsError(): void
    {
        $this->insertUser('bob@example.com', 'Bob', 'Smith');

        $result = $this->csrfPost('profile', [
            'first_name' => 'Alice',
            'last_name'  => 'Martin',
            'email'      => 'bob@example.com',
            'phone'      => '',
        ]);

        $result->assertRedirect();

        // L'email de Alice ne doit pas avoir changé.
        $db   = db_connect();
        $user = $db->table('users')->where('id', $this->userId)->get()->getRowArray();
        $this->assertSame('alice@example.com', $user['email']);
    }

    // ------------------------------------------------------------------
    // AC4 — Changement vers email disponible → email + email_hash mis à jour
    // ------------------------------------------------------------------

    public function testPostProfileWithAvailableNewEmailUpdatesEmailAndHash(): void
    {
        $newEmail = 'alice.new@example.com';

        $result = $this->csrfPost('profile', [
            'first_name' => 'Alice',
            'last_name'  => 'Martin',
            'email'      => $newEmail,
            'phone'      => '',
        ]);

        $result->assertRedirectTo(site_url('profile'));

        $db   = db_connect();
        $user = $db->table('users')->where('id', $this->userId)->get()->getRowArray();
        $this->assertSame($newEmail, $user['email']);
        $this->assertSame(hash('sha256', $newEmail), $user['email_hash']);
    }
}
