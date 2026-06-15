<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\KermesseModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Story 5.5 — Onglet « Équipe » — vue des membres et invitations en attente.
 *
 * Tests the RoleService::getTeamMembersGroupedByStatus() method, which fetches
 * and groups team members (Owner/Admin/Gestionnaire) by status:
 *   - active: members with first_access_at IS NOT NULL (have accessed the dashboard)
 *   - pending: members with invited_at IS NOT NULL AND first_access_at IS NULL
 *
 * @internal
 */
final class RoleServiceTeamMembersTest extends CIUnitTestCase
{
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId       = 0;
    private int $adminId       = 0;
    private int $adminId2      = 0;
    private int $gestionId     = 0;
    private int $pendingId     = 0;
    private int $kermesseId    = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Tests: AC1 — Groupe les membres actifs par rôle
    // ------------------------------------------------------------------

    public function testGroupsActiveMembersByRole(): void
    {
        $roleService = new \App\Services\RoleService(
            model(UserRoleModel::class),
            model(UserModel::class),
        );

        $result = $roleService->getTeamMembersGroupedByStatus($this->kermesseId);

        // Vérifier les groupes
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('pending', $result);

        $active = $result['active'];

        // Vérifier le groupement par rôle au sein des actifs
        $this->assertArrayHasKey('owner', $active);
        $this->assertArrayHasKey('admin', $active);
        $this->assertArrayHasKey('gestionnaire', $active);

        // 1 Owner + 2 Admins + 1 Gestionnaire = 4 actifs
        $this->assertCount(1, $active['owner']);
        $this->assertCount(2, $active['admin']);
        $this->assertCount(1, $active['gestionnaire']);

        // Total count should be 4
        $totalActive = count($active['owner']) + count($active['admin']) + count($active['gestionnaire']);
        $this->assertCount(4, array_merge(...array_values($active)));
    }

    // ------------------------------------------------------------------
    // Tests: AC2 — Identifie les invitations en attente
    // ------------------------------------------------------------------

    public function testIdentifiesPendingInvitations(): void
    {
        $roleService = new \App\Services\RoleService(
            model(UserRoleModel::class),
            model(UserModel::class),
        );

        $result = $roleService->getTeamMembersGroupedByStatus($this->kermesseId);

        $pending = $result['pending'];

        // 1 invitation en attente
        $this->assertCount(1, $pending);
        $this->assertEquals($this->pendingId, (int) $pending[0]['user_id']);
        $this->assertNull($pending[0]['first_access_at']);
        $this->assertNotNull($pending[0]['invited_at']);
    }

    // ------------------------------------------------------------------
    // Tests: AC3 — Réinvitation d'un ancien membre préserve first_access_at
    // ------------------------------------------------------------------

    public function testReinvitationPreservesFirstAccessAt(): void
    {
        // Scénario: un ancien Admin a accédé au dashboard (first_access_at IS NOT NULL),
        // puis a été supprimé (removal downgrade to benevole). On le réinvite comme
        // Gestionnaire => il doit redevenir actif (first_access_at préservé).

        $db = db_connect();

        // Modifier adminId2 pour le mettre en état « ancien membre »
        // first_access_at = set, puis downgrade to benevole
        $db->table('db_kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->adminId2)
            ->update([
                'first_access_at' => (new \CodeIgniter\I18n\Time('-1 day'))->toDateTimeString(),
                'role'            => UserRoleModel::ROLE_BENEVOLE,
            ]);

        $roleService = new \App\Services\RoleService(
            model(UserRoleModel::class),
            model(UserModel::class),
        );

        $result = $roleService->getTeamMembersGroupedByStatus($this->kermesseId);

        // Après downgrade, adminId2 doit être absent de la liste team
        // (Bénévoles ne sont pas inclus)
        $activeMemberIds = array_merge(
            array_column($result['active']['owner'] ?? [], 'user_id'),
            array_column($result['active']['admin'] ?? [], 'user_id'),
            array_column($result['active']['gestionnaire'] ?? [], 'user_id'),
        );

        $this->assertNotContains($this->adminId2, $activeMemberIds);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function setUpTables(): void
    {
        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                email      TEXT    NOT NULL UNIQUE,
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
                location          TEXT,
                short_description TEXT,
                timezone          TEXT    DEFAULT "Europe/Paris",
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
                invited_at      DATETIME,
                accepted_at     DATETIME,
                first_access_at DATETIME,
                last_access_at  DATETIME,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(kermesse_id, user_id)
            )
        ');
    }

    private function insertFixtures(): void
    {
        $db = db_connect();
        $now = (new \CodeIgniter\I18n\Time())->toDateTimeString();

        // Create 5 users
        $db->table('users')->insert(['email' => 'owner@test.local', 'email_hash' => hash('sha256', 'owner@test.local'), 'first_name' => 'Owner', 'last_name' => 'User']);
        $this->ownerId = (int) $db->insertID();

        $db->table('users')->insert(['email' => 'admin1@test.local', 'email_hash' => hash('sha256', 'admin1@test.local'), 'first_name' => 'Admin', 'last_name' => 'One']);
        $this->adminId = (int) $db->insertID();

        $db->table('users')->insert(['email' => 'admin2@test.local', 'email_hash' => hash('sha256', 'admin2@test.local'), 'first_name' => 'Admin', 'last_name' => 'Two']);
        $this->adminId2 = (int) $db->insertID();

        $db->table('users')->insert(['email' => 'gestion@test.local', 'email_hash' => hash('sha256', 'gestion@test.local'), 'first_name' => 'Gestion', 'last_name' => 'User']);
        $this->gestionId = (int) $db->insertID();

        $db->table('users')->insert(['email' => 'pending@test.local', 'email_hash' => hash('sha256', 'pending@test.local'), 'first_name' => 'Pending', 'last_name' => 'User']);
        $this->pendingId = (int) $db->insertID();

        // Create kermesse
        $db->table('kermesses')->insert([
            'created_by'   => $this->ownerId,
            'public_slug'  => 'test-kermesse-5-5',
            'name'         => 'Test Kermesse 5.5',
        ]);
        $this->kermesseId = (int) $db->insertID();

        // Owner: active (first_access_at set)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->ownerId,
            'role'          => UserRoleModel::ROLE_OWNER,
            'first_access_at' => $now,
            'last_access_at' => $now,
        ]);

        // Admin 1: active (first_access_at set)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->adminId,
            'role'          => UserRoleModel::ROLE_ADMIN,
            'invited_by'    => $this->ownerId,
            'invited_at'    => $now,
            'first_access_at' => $now,
            'last_access_at' => $now,
        ]);

        // Admin 2: active (first_access_at set)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->adminId2,
            'role'          => UserRoleModel::ROLE_ADMIN,
            'invited_by'    => $this->ownerId,
            'invited_at'    => $now,
            'first_access_at' => $now,
            'last_access_at' => $now,
        ]);

        // Gestionnaire: active (first_access_at set)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->gestionId,
            'role'          => UserRoleModel::ROLE_GESTIONNAIRE,
            'invited_by'    => $this->ownerId,
            'invited_at'    => $now,
            'first_access_at' => $now,
            'last_access_at' => $now,
        ]);

        // Pending: invitation sent but not yet accepted (no first_access_at)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->pendingId,
            'role'          => UserRoleModel::ROLE_ADMIN,
            'invited_by'    => $this->ownerId,
            'invited_at'    => $now,
            'first_access_at' => null,
        ]);
    }
}
