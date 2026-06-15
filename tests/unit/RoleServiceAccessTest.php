<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\RoleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for RoleService::recordAccess — Story 5.2.
 *
 * Validates that first_access_at is set only on the first call and that
 * last_access_at is updated on every call.
 *
 * @internal
 */
final class RoleServiceAccessTest extends CIUnitTestCase
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
    }

    private function insertOwner(int $kermesseId): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => 'owner@access-5-2.test',
            'email_hash' => hash('sha256', 'owner@access-5-2.test'),
        ]);
        $userId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $kermesseId,
            'user_id'       => $userId,
            'role'          => UserRoleModel::ROLE_OWNER,
            'first_access_at' => null,
            'last_access_at'  => null,
        ]);

        return $userId;
    }

    private function insertKermesse(int $ownerId): int
    {
        $db = db_connect();
        $db->table('kermesses')->insert([
            'created_by'  => $ownerId,
            'public_slug' => 'access-test-52',
            'name'        => 'Kermesse Access Test 5.2',
            'status'      => 'open',
        ]);
        return (int) $db->insertID();
    }

    private function makeService(): RoleService
    {
        return new RoleService(model(UserRoleModel::class), model(UserModel::class));
    }

    private function fetchRow(int $kermesseId, int $userId): array
    {
        $row = db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $kermesseId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row, 'Role row not found for test user');

        return $row;
    }

    // ------------------------------------------------------------------

    public function testRecordAccessSetsFirstAccessAtOnFirstCall(): void
    {
        $db          = db_connect();
        $db->table('users')->insert([
            'email'      => 'first@access-5-2.test',
            'email_hash' => hash('sha256', 'first@access-5-2.test'),
        ]);
        $userId      = (int) $db->insertID();
        $db->table('kermesses')->insert([
            'created_by'  => $userId,
            'public_slug' => 'first-access-52',
            'name'        => 'Première visite',
            'status'      => 'open',
        ]);
        $kermesseId = (int) $db->insertID();
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => UserRoleModel::ROLE_OWNER,
        ]);

        $before = date('Y-m-d H:i:s');
        $this->makeService()->recordAccess($kermesseId, $userId);
        $after  = date('Y-m-d H:i:s');

        $row = $this->fetchRow($kermesseId, $userId);
        $this->assertNotNull($row['first_access_at'], 'first_access_at doit être positionné au 1er accès');
        $this->assertGreaterThanOrEqual($before, $row['first_access_at']);
        $this->assertLessThanOrEqual($after,  $row['first_access_at']);
    }

    public function testRecordAccessDoesNotOverwriteExistingFirstAccessAt(): void
    {
        $db     = db_connect();
        $db->table('users')->insert([
            'email'      => 'second@access-5-2.test',
            'email_hash' => hash('sha256', 'second@access-5-2.test'),
        ]);
        $userId = (int) $db->insertID();
        $db->table('kermesses')->insert([
            'created_by'  => $userId,
            'public_slug' => 'second-access-52',
            'name'        => 'Deuxième visite',
            'status'      => 'open',
        ]);
        $kermesseId      = (int) $db->insertID();
        $originalFirst   = '2026-01-10 08:00:00';
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $kermesseId,
            'user_id'       => $userId,
            'role'          => UserRoleModel::ROLE_OWNER,
            'first_access_at' => $originalFirst,
        ]);

        $this->makeService()->recordAccess($kermesseId, $userId);

        $row = $this->fetchRow($kermesseId, $userId);
        $this->assertSame(
            $originalFirst,
            $row['first_access_at'],
            'first_access_at ne doit pas être ré-écrit si déjà positionné',
        );
    }

    public function testRecordAccessUpdatesLastAccessAtOnEveryCall(): void
    {
        $db     = db_connect();
        $db->table('users')->insert([
            'email'      => 'last@access-5-2.test',
            'email_hash' => hash('sha256', 'last@access-5-2.test'),
        ]);
        $userId = (int) $db->insertID();
        $db->table('kermesses')->insert([
            'created_by'  => $userId,
            'public_slug' => 'last-access-52',
            'name'        => 'Dernier accès',
            'status'      => 'open',
        ]);
        $kermesseId = (int) $db->insertID();
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => UserRoleModel::ROLE_OWNER,
        ]);

        $before = date('Y-m-d H:i:s');
        $this->makeService()->recordAccess($kermesseId, $userId);
        $after  = date('Y-m-d H:i:s');

        $row = $this->fetchRow($kermesseId, $userId);
        $this->assertNotNull($row['last_access_at'], 'last_access_at doit être positionné');
        $this->assertGreaterThanOrEqual($before, $row['last_access_at']);
        $this->assertLessThanOrEqual($after,  $row['last_access_at']);
    }

    public function testRecordAccessWithUnknownUserDoesNotThrow(): void
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => 'noop@access-5-2.test',
            'email_hash' => hash('sha256', 'noop@access-5-2.test'),
        ]);
        $userId = (int) $db->insertID();
        $db->table('kermesses')->insert([
            'created_by'  => $userId,
            'public_slug' => 'noop-access-52',
            'name'        => 'No role kermesse',
            'status'      => 'open',
        ]);
        $kermesseId = (int) $db->insertID();
        // No role row inserted — recordAccess must silently return.

        $service = $this->makeService();
        $this->expectNotToPerformAssertions();
        $service->recordAccess($kermesseId, $userId);
    }
}
