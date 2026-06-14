<?php

namespace Tests\Unit;

use App\Models\KermesseModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for UserRoleModel — Story 1.5.
 *
 * @internal
 */
class UserRoleModelTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

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
                public_slug       TEXT    UNIQUE,
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
                user_id      INTEGER NOT NULL,
                role         TEXT    NOT NULL,
                invited_by   INTEGER,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    public function testFindKermessesForUserReturnsOrderedKermessesWithRoles(): void
    {
        $userModel     = model(UserModel::class);
        $kermesseModel = model(KermesseModel::class);
        $roleModel     = model(UserRoleModel::class);

        $userModel->skipValidation(true);
        $userId = $userModel->insert([
            'email'      => 'test@example.com',
            'email_hash' => hash('sha256', 'test@example.com'),
        ]);

        $kermesseModel->skipValidation(true);
        $k1Id = $kermesseModel->insert(['name' => 'B Kermesse', 'created_by' => $userId, 'public_slug' => 'b-k']);
        $k2Id = $kermesseModel->insert(['name' => 'A Kermesse', 'created_by' => $userId, 'public_slug' => 'a-k']);
        $kermesseModel->insert(['name' => 'C Kermesse', 'created_by' => $userId, 'public_slug' => 'c-k']);

        $roleModel->insert(['user_id' => $userId, 'kermesse_id' => $k1Id, 'role' => UserRoleModel::ROLE_BENEVOLE]);
        $roleModel->insert(['user_id' => $userId, 'kermesse_id' => $k2Id, 'role' => UserRoleModel::ROLE_OWNER]);

        $results = $roleModel->findKermessesForUser((int) $userId);

        $this->assertCount(2, $results);
        $this->assertSame('A Kermesse', $results[0]['name']);
        $this->assertSame(UserRoleModel::ROLE_OWNER, $results[0]['role']);
        $this->assertSame('B Kermesse', $results[1]['name']);
        $this->assertSame(UserRoleModel::ROLE_BENEVOLE, $results[1]['role']);
    }
}
