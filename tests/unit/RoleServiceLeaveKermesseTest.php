<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\RoleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for RoleService::leaveKermesse — Story 5.9.
 *
 * Scénarios :
 *   - Owner → failure('unauthorized_role'), ligne kermesse_user_roles conservée
 *   - Inscriptions actives → failure('has_active_signups'), ligne conservée
 *   - Non-Owner sans inscription active → success() ET ligne supprimée
 *   - Non-membre (aucune ligne) → failure sans exception fatale (idempotence)
 *
 * @internal
 */
final class RoleServiceLeaveKermesseTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
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
    // T1 — Owner → failure('unauthorized_role'), ligne conservée
    // ------------------------------------------------------------------

    public function testLeaveKermesseOwnerIsRejectedWithUnauthorizedRole(): void
    {
        [$kermesseId, $userId] = $this->fixture('owner');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertFalse($result->success);
        $this->assertSame('unauthorized_role', $result->errorCode);
        $this->assertSame('owner', $this->roleForUser($kermesseId, $userId),
            'La ligne kermesse_user_roles de l\'Owner doit être conservée.');
    }

    // ------------------------------------------------------------------
    // T2 — Inscriptions actives → failure('has_active_signups'), ligne conservée
    // ------------------------------------------------------------------

    public function testLeaveKermesseWithActiveSignupsIsRejected(): void
    {
        [$kermesseId, $userId] = $this->fixture('benevole');
        $this->insertSignup($kermesseId, $userId, 'active');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertFalse($result->success);
        $this->assertSame('has_active_signups', $result->errorCode,
            'Un utilisateur avec des inscriptions actives doit recevoir le code has_active_signups.');
        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'La ligne kermesse_user_roles doit être conservée quand il y a des inscriptions actives.');
    }

    // ------------------------------------------------------------------
    // T3 — Non-Owner, Admin/Gestionnaire, sans inscription active → success, ligne supprimée
    // ------------------------------------------------------------------

    public function testLeaveKermesseSucceedsForAdminWithoutActiveSignups(): void
    {
        [$kermesseId, $userId] = $this->fixture('admin');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertTrue($result->success);
        $this->assertNull($result->errorCode);
        $this->assertNull($this->roleForUser($kermesseId, $userId),
            'La ligne kermesse_user_roles doit être supprimée après un départ réussi.');
    }

    public function testLeaveKermesseSucceedsForBenevoleWithoutActiveSignups(): void
    {
        [$kermesseId, $userId] = $this->fixture('benevole');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertTrue($result->success);
        $this->assertNull($this->roleForUser($kermesseId, $userId),
            'Un bénévole sans inscription active peut quitter la kermesse.');
    }

    // ------------------------------------------------------------------
    // T4 — Inscription inactive (cancelled) ne compte pas comme active
    // ------------------------------------------------------------------

    public function testLeaveKermesseSucceedsWhenOnlyCancelledSignups(): void
    {
        [$kermesseId, $userId] = $this->fixture('benevole');
        $this->insertSignup($kermesseId, $userId, 'cancelled');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertTrue($result->success,
            'Une inscription annulée ne doit pas bloquer le départ.');
        $this->assertNull($this->roleForUser($kermesseId, $userId));
    }

    // ------------------------------------------------------------------
    // T5 — Non-membre (aucune ligne) → failure sans exception fatale (idempotence)
    // ------------------------------------------------------------------

    public function testLeaveKermesseForNonMemberDoesNotThrow(): void
    {
        [$kermesseId, ] = $this->fixture('owner');
        $nonMemberId    = $this->insertUser('nonmember-59@kermesse.test');

        $result = $this->makeService()->leaveKermesse($kermesseId, $nonMemberId);

        $this->assertFalse($result->success,
            'Un non-membre doit recevoir un résultat d\'échec, pas une exception.');
        $this->assertSame('unauthorized_role', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeService(): RoleService
    {
        return new RoleService(model(UserRoleModel::class), model(UserModel::class));
    }

    /** @return array{int, int} [kermesseId, userId] */
    private function fixture(string $role): array
    {
        $db = db_connect();

        $ownerId = $this->insertUser('owner-59@kermesse.test');
        $userId  = ($role === 'owner') ? $ownerId : $this->insertUser('member-59@kermesse.test');

        $db->table('kermesses')->insert([
            'created_by'  => $ownerId,
            'public_slug' => 'leave-59-' . uniqid(),
            'name'        => 'Kermesse Leave 5.9',
            'status'      => 'open',
        ]);
        $kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'    => $kermesseId,
            'user_id'        => $userId,
            'role'           => $role,
            'first_access_at' => '2026-01-01 10:00:00',
        ]);

        return [$kermesseId, $userId];
    }

    private function insertUser(string $email): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
        ]);

        return (int) $db->insertID();
    }

    private function insertSignup(int $kermesseId, int $userId, string $status): void
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $kermesseId,
            'name'          => 'Stand test 5.9',
            'display_order' => 1,
        ]);
        $standId = (int) $db->insertID();

        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2099-01-01 09:00:00',
            'ends_at'   => '2099-01-01 12:00:00',
            'capacity'  => 5,
        ]);
        $slotId = (int) $db->insertID();

        $row = ['slot_id' => $slotId, 'user_id' => $userId];
        $row += match ($status) {
            'cancelled' => ['canceled_at' => '2026-01-01 00:00:00', 'canceled_by' => $userId],
            'removed'   => ['canceled_at' => '2026-01-01 00:00:00', 'canceled_by' => 9999],
            default     => [],
        };
        $db->table('slot_signups')->insert($row);
    }

    private function roleForUser(int $kermesseId, int $userId): ?string
    {
        $row = db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $kermesseId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        return $row !== null ? (string) $row['role'] : null;
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
                public_slug       TEXT    UNIQUE,
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
            DROP TABLE IF EXISTS db_slot_signups;
            CREATE TABLE IF NOT EXISTS db_slot_signups (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id                   INTEGER  NOT NULL,
                user_id                   INTEGER  NULL,
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
}
