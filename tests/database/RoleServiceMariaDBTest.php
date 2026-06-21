<?php

declare(strict_types=1);

use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\MigrationRunnerService;
use App\Services\RoleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Story 6.6 — Role protection invariants on real MariaDB 10.11.
 *
 * Ports the SQLite-backed RoleServiceRemoveRoleTest and
 * RoleServiceLeaveKermesseTest scenarios to run against the real
 * production schema via MigrationRunnerService.
 *
 * Critical properties proved on MariaDB:
 *   - Owner protection: removeRole() is a no-op for owners
 *   - Non-owner without active signups → downgraded to benevole
 *   - Pending invite without active signups → row deleted
 *   - Pending invite with active signups → downgraded to benevole
 *   - leaveKermesse() rejected for Owner
 *   - leaveKermesse() rejected when active signups exist
 *   - leaveKermesse() succeeds for non-owner without active signups
 *   - Cancelled signups do not count as active
 *
 * The SQLite tests use manually-created simplified schemas; this suite
 * uses the authoritative MariaDB schema (all 14 migrations), proving the
 * ENUM types, FK constraints, and query semantics match production.
 *
 * @group mariadb
 * @internal
 */
final class RoleServiceMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('RoleServiceMariaDBTest must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        $this->dropAll($db);

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName       = 'kermesse_role_service_mariadb_' . uniqid();
        config('Kermesse')->opsMigrationPath           = ROOTPATH . 'database/migrations_sql';

        $result = (new MigrationRunnerService($db))->run();
        if (! $result['ok']) {
            $this->fail('Migrations failed: ' . implode(', ', $result['failed']));
        }
    }

    protected function tearDown(): void
    {
        $this->dropAll(db_connect('tests'));
        parent::tearDown();
    }

    // ======================================================================
    // removeRole — Owner protection
    // ======================================================================

    public function testRemoveRoleOwnerIsNoOpOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'owner');

        $result = $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertFalse($result, 'removeRole() must return false for an owner (no-op)');
        $this->assertSame('owner', $this->roleForUser($kermesseId, $userId),
            'Owner role must not be changed on MariaDB');
    }

    // ------------------------------------------------------------------
    // removeRole — Pending invite, no active signups → deleted
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithoutSignupsDeletesRowOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin', pendingInvite: true);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertNull($this->roleForUser($kermesseId, $userId),
            'Pending invite without signups must be deleted on MariaDB');
    }

    // ------------------------------------------------------------------
    // removeRole — Pending invite, with active signups → benevole
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithActiveSignupsDowngradesToBenevoleOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin', pendingInvite: true);
        $this->insertSignup($kermesseId, $userId, 'active');

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Pending invite with active signups must be downgraded to benevole on MariaDB');
    }

    // ------------------------------------------------------------------
    // removeRole — Active member, no signups → benevole
    // ------------------------------------------------------------------

    public function testRemoveRoleActiveMemberWithoutSignupsDowngradesToBenevoleOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin', pendingInvite: false);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Active member without signups must be downgraded to benevole on MariaDB');
    }

    // ------------------------------------------------------------------
    // removeRole — Cancelled signup does NOT count as active
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithInactiveSignupsDeletesRowOnMariaDB(): void
    {
        $inactiveStatuses = ['cancelled', 'removed', 'rejected', 'deleted'];

        foreach ($inactiveStatuses as $status) {
            // Need a fresh kermesse/user for each status to avoid state bleed
            [$kermesseId, $userId] = $this->fixture(role: 'admin', pendingInvite: true);
            $this->insertSignup($kermesseId, $userId, $status);

            $this->makeService()->removeRole($kermesseId, $userId, 0);

            $this->assertNull($this->roleForUser($kermesseId, $userId),
                "Signup with status '{$status}' must not count as active: row must be deleted on MariaDB");
        }
    }

    // ------------------------------------------------------------------
    // removeRole — Mid-confirmation (accepted_at != null, first_access_at == null)
    // ------------------------------------------------------------------

    public function testRemoveRoleMidConfirmationDowngradesToBenevoleOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin', pendingInvite: true);
        db_connect('tests')->table('kermesse_user_roles')
            ->where(['kermesse_id' => $kermesseId, 'user_id' => $userId])
            ->update(['accepted_at' => '2026-01-01 10:00:00']);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Mid-confirmation user must be downgraded to benevole, not deleted');
    }

    // ------------------------------------------------------------------
    // removeRole — Auto-revocation and Successive mutations
    // ------------------------------------------------------------------

    public function testAutoRevocationDowngradesToBenevoleOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin');

        $result = $this->makeService()->removeRole($kermesseId, $userId, $userId);

        $this->assertTrue($result);
        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Admin self-revocation must downgrade to benevole');
    }

    public function testConcurrentRemoveRoleDoesNotCrashIfAlreadyRemovedOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin');

        // Mutation 1: remove
        $res1 = $this->makeService()->removeRole($kermesseId, $userId, 0);
        $this->assertTrue($res1);

        // Mutation 2: remove again (concurrent/successive)
        $res2 = $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertFalse($res2, 'Second removeRole should return false cleanly');
    }

    // ======================================================================
    // leaveKermesse — Owner cannot leave
    // ======================================================================

    public function testLeaveKermesseOwnerRejectedOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'owner', pendingInvite: false);

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertFalse($result->success);
        $this->assertSame('unauthorized_role', $result->errorCode);
        $this->assertSame('owner', $this->roleForUser($kermesseId, $userId),
            'Owner row must be preserved after rejected leave on MariaDB');
    }

    // ------------------------------------------------------------------
    // leaveKermesse — Active signups block departure
    // ------------------------------------------------------------------

    public function testLeaveKermesseWithActiveSignupsRejectedOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'benevole', pendingInvite: false);
        $this->insertSignup($kermesseId, $userId, 'active');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertFalse($result->success);
        $this->assertSame('has_active_signups', $result->errorCode);
        $this->assertNotNull($this->roleForUser($kermesseId, $userId),
            'Role must be preserved when leaving is blocked by active signups on MariaDB');
    }

    // ------------------------------------------------------------------
    // leaveKermesse — Non-owner without active signups → row deleted
    // ------------------------------------------------------------------

    public function testLeaveKermesseSucceedsForNonOwnerWithoutSignupsOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'admin', pendingInvite: false);

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertTrue($result->success);
        $this->assertNull($this->roleForUser($kermesseId, $userId),
            'Role row must be deleted after successful leave on MariaDB');
    }

    // ------------------------------------------------------------------
    // leaveKermesse — Cancelled signups do NOT block departure
    // ------------------------------------------------------------------

    public function testLeaveKermesseSucceedsWhenOnlyCancelledSignupsOnMariaDB(): void
    {
        [$kermesseId, $userId] = $this->fixture(role: 'benevole', pendingInvite: false);
        $this->insertSignup($kermesseId, $userId, 'cancelled');

        $result = $this->makeService()->leaveKermesse($kermesseId, $userId);

        $this->assertTrue($result->success,
            'Cancelled signup must not block leave on MariaDB');
        $this->assertNull($this->roleForUser($kermesseId, $userId));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private function makeService(): RoleService
    {
        return new RoleService(
            new UserRoleModel(),
            new UserModel(),
        );
    }

    /** @return array{int, int} [kermesseId, targetUserId] */
    private function fixture(string $role, bool $pendingInvite = false): array
    {
        $userFab = new \CodeIgniter\Test\Fabricator(\App\Models\UserModel::class);
        $kermesseFab = new \CodeIgniter\Test\Fabricator(\App\Models\KermesseModel::class);
        $roleFab = new \CodeIgniter\Test\Fabricator(\App\Models\UserRoleModel::class);

        $ownerEmail = 'owner-role-' . uniqid() . '@mariadb.test';
        $ownerData = $userFab->create([
            'email' => $ownerEmail,
            'email_hash' => hash('sha256', $ownerEmail),
            'first_name' => 'Owner',
            'last_name' => 'Role',
            'phone' => ''
        ]);
        $ownerId = $ownerData['id'];

        $targetEmail = 'target-role-' . uniqid() . '@mariadb.test';
        $targetData = $userFab->create([
            'email' => $targetEmail,
            'email_hash' => hash('sha256', $targetEmail),
            'first_name' => 'Target',
            'last_name' => 'Role',
            'phone' => ''
        ]);
        $userId = $role === 'owner' ? $ownerId : $targetData['id'];

        $kermesseData = $kermesseFab->create([
            'created_by' => $ownerId,
            'public_slug' => 'role-mariadb-' . uniqid(),
            'name' => 'Kermesse Role MariaDB',
            'event_date' => '2026-09-01',
            'location' => 'Salle',
            'status' => 'open'
        ]);
        $kermesseId = $kermesseData['id'];

        $roleData = [
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => $role,
        ];

        if ($pendingInvite) {
            $roleData['invited_at']      = date('Y-m-d H:i:s');
            $roleData['first_access_at'] = null;
        } else {
            $roleData['first_access_at'] = '2026-01-01 10:00:00';
        }

        $roleFab->create($roleData);

        return [$kermesseId, $userId];
    }

    private function insertSignup(int $kermesseId, int $userId, string $status): void
    {
        $standFab = new \CodeIgniter\Test\Fabricator(\App\Models\StandModel::class);
        $slotFab = new \CodeIgniter\Test\Fabricator(\App\Models\SlotModel::class);
        $signupFab = new \CodeIgniter\Test\Fabricator(\App\Models\SlotSignupModel::class);

        $standData = $standFab->create([
            'kermesse_id' => $kermesseId,
            'name' => 'Stand Role Test',
            'display_order' => 1,
            'status' => 'active'
        ]);
        $standId = $standData['id'];

        $slotData = $slotFab->create([
            'stand_id' => $standId,
            'starts_at' => '2099-01-01 09:00:00',
            'ends_at' => '2099-01-01 12:00:00',
            'capacity' => 5
        ]);
        $slotId = $slotData['id'];

        // Status is now stateless (no status column): use timestamps to represent state
        $row = [
            'slot_id' => $slotId,
            'user_id' => $userId,
            'email' => 'target@test.com',
            'first_name' => 'T',
            'last_name' => 'R',
            'phone' => ''
        ];

        if ($status === 'cancelled') {
            $row['canceled_at'] = '2026-01-01 00:00:00';
            $row['canceled_by'] = $userId;
        } elseif ($status === 'removed') {
            $row['canceled_at'] = '2026-01-01 00:00:00';
            $row['canceled_by'] = 9999;
        } elseif ($status === 'rejected') {
            $row['rejected_at'] = '2026-01-01 00:00:00';
        } elseif ($status === 'deleted') {
            $row['deleted_at'] = '2026-01-01 00:00:00';
        }
        // 'active' = no canceled_at, no deleted_at, no rejected_at

        $signupFab->create($row);
    }

    private function roleForUser(int $kermesseId, int $userId): ?string
    {
        $row = db_connect('tests')
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $kermesseId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        return $row !== null ? (string) $row['role'] : null;
    }

    private function dropAll(\CodeIgniter\Database\ConnectionInterface $db): void
    {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $db->query('SHOW FULL TABLES WHERE TABLE_TYPE = "BASE TABLE"')->getResultArray();
        foreach ($tables as $row) {
            $db->query('DROP TABLE IF EXISTS `' . array_values($row)[0] . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
