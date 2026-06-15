<?php

use App\Models\ProfileDivergenceModel;
use App\Models\UserModel;
use App\Services\ProfileService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for ProfileService::updateOwnProfile — Story 5.7.
 *
 * Vérifie la mise à jour des coordonnées utilisateur, y compris la
 * synchronisation du email_hash lors d'un changement d'adresse.
 *
 * @internal
 */
final class ProfileUpdateServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        $db->query('
            CREATE TABLE IF NOT EXISTS db_profile_divergences (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id              INTEGER NOT NULL,
                kermesse_id          INTEGER NOT NULL,
                signup_id            INTEGER,
                submitted_first_name TEXT    NOT NULL DEFAULT "",
                submitted_last_name  TEXT    NOT NULL DEFAULT "",
                submitted_phone      TEXT    NOT NULL DEFAULT "",
                last_login_at        DATETIME NULL DEFAULT NULL,
                resolved_at          DATETIME,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_profile_divergences');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    private function insertUser(
        string $email,
        string $firstName = 'Alice',
        string $lastName  = 'Martin',
        string $phone     = '',
    ): int {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
        ]);

        return (int) $db->insertID();
    }

    private function makeService(): ProfileService
    {
        return new ProfileService(new UserModel(), new ProfileDivergenceModel());
    }

    // ------------------------------------------------------------------
    // Mise à jour des coordonnées sans changement d'email
    // ------------------------------------------------------------------

    public function testUpdateOwnProfileUpdatesNameAndPhone(): void
    {
        $userId  = $this->insertUser('alice@example.com', 'Alice', 'Martin', '0611111111');
        $service = $this->makeService();

        $result = $service->updateOwnProfile($userId, 'alice@example.com', 'Alicia', 'Dupont', '0699999999');

        $this->assertTrue($result);

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('Alicia', $user['first_name']);
        $this->assertSame('Dupont', $user['last_name']);
        $this->assertSame('0699999999', $user['phone']);
        // Email inchangé → hash inchangé.
        $this->assertSame('alice@example.com', $user['email']);
        $this->assertSame(hash('sha256', 'alice@example.com'), $user['email_hash']);
    }

    // ------------------------------------------------------------------
    // Changement d'email → email_hash synchronisé
    // ------------------------------------------------------------------

    public function testUpdateOwnProfileWithEmailChangeSyncsEmailHash(): void
    {
        $userId   = $this->insertUser('alice@example.com');
        $newEmail = 'alice.new@example.com';
        $service  = $this->makeService();

        $result = $service->updateOwnProfile($userId, $newEmail, 'Alice', 'Martin', '');

        $this->assertTrue($result);

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame($newEmail, $user['email']);
        $this->assertSame(hash('sha256', $newEmail), $user['email_hash']);
    }

    // ------------------------------------------------------------------
    // Utilisateur inconnu → retourne false
    // ------------------------------------------------------------------

    public function testUpdateOwnProfileReturnsFalseForUnknownUser(): void
    {
        $service = $this->makeService();

        $result = $service->updateOwnProfile(9999, 'nobody@example.com', 'X', 'Y', '');

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // Email identique (même casse) → pas de mise à jour de l'email dans les données
    // ------------------------------------------------------------------

    public function testUpdateOwnProfileWithSameEmailDoesNotChangeEmailHash(): void
    {
        $userId  = $this->insertUser('alice@example.com');
        $service = $this->makeService();

        $originalHash = hash('sha256', 'alice@example.com');

        $service->updateOwnProfile($userId, 'ALICE@example.com', 'Alice', 'Martin', '');

        // Email normalisé en lowercase est identique → email_hash ne doit pas changer.
        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('alice@example.com', $user['email']);
        $this->assertSame($originalHash, $user['email_hash']);
    }
}
