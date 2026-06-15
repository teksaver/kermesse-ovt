<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\KermesseModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 5.5 — Onglet « Équipe » — gestion des cas limites d'invitations.
 *
 * AC2: Prévention de doublons — si un email possède déjà le rôle demandé,
 * l'invitation échoue avec `already_has_role`.
 *
 * AC3: Réinvitation d'ancien membre — si un ancien membre (first_access_at IS NOT NULL)
 * est réinvité, son rôle est mis à jour sans réinitialiser first_access_at.
 *
 * @internal
 */
final class InvitationEdgeCasesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private int $ownerId       = 0;
    private int $adminId       = 0;
    private int $existingId    = 0;
    private int $formerMemberId = 0;
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
        $db->query('DELETE FROM db_email_events');
        $db->query('DELETE FROM db_access_tokens');
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
                user_id      INTEGER NOT NULL,
                role         TEXT    NOT NULL,
                invited_by   INTEGER,
                invited_at      DATETIME NULL DEFAULT NULL,
                accepted_at     DATETIME NULL DEFAULT NULL,
                first_access_at DATETIME NULL DEFAULT NULL,
                last_access_at  DATETIME NULL DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(kermesse_id, user_id)
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_email_events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type  TEXT NOT NULL,
                recipient_email TEXT NOT NULL,
                status      TEXT NOT NULL DEFAULT "pending",
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
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
    }

    // ------------------------------------------------------------------
    // AC2 — Prévention des doublons : already_has_role
    // ------------------------------------------------------------------

    public function testInviteDuplicateRoleReturnsAlreadyHasRole(): void
    {
        // Tentative d'inviter un Admin existant comme Admin → already_has_role
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => 'admin@edge-cases.test',
                'role'  => 'admin',
            ]));

        // Vérifier que le message d'erreur est flashé avec mention du rôle
        $result->assertSessionHas('invite_error');
        $result->assertSessionMissing('invite_success');
        $this->assertStringContainsString('administrateur', session()->getFlashdata('invite_error'));

        // Vérifier qu'aucun doublon n'a été créé
        $count = db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->adminId)
            ->countAllResults();
        $this->assertSame(1, $count, 'Aucun doublon ne doit être créé');
    }

    public function testInviteToUpgradeRoleIsAllowed(): void
    {
        // Un Gestionnaire peut être upgradé en Admin (différent du rôle actuel)
        // Cette invitation doit réussir
        $gestionnaire = $this->createUserWithRole('gestion@edge-cases.test', 'gestionnaire');

        $this->mockEmailSend(true);
        try {
            $result = $this->withSession($this->session($this->ownerId))
                ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                    'email' => 'gestion@edge-cases.test',
                    'role'  => 'admin',
                ]));
        } finally {
            \Config\Services::resetSingle('email');
        }

        // Vérifier que le rôle a été mis à jour
        $role = db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $gestionnaire)
            ->get()
            ->getRowArray();

        $this->assertSame('admin', $role['role']);
    }

    // ------------------------------------------------------------------
    // AC3 — Réinvitation d'ancien membre préserve first_access_at
    // ------------------------------------------------------------------

    public function testReinvitationPreservesFirstAccessAt(): void
    {
        $db = db_connect();

        // Vérifier que formerMemberId a first_access_at défini et role = benevole
        $beforeReinvite = $db->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->formerMemberId)
            ->get()
            ->getRowArray();

        $originalFirstAccess = $beforeReinvite['first_access_at'];
        $this->assertNotNull($originalFirstAccess, 'L\'ancien membre doit avoir first_access_at défini');
        $this->assertSame('benevole', $beforeReinvite['role'], 'L\'ancien membre doit être downgrade en benevole');

        // Réinviter comme Admin
        $this->mockEmailSend(true);
        try {
            $this->withSession($this->session($this->ownerId))
                ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                    'email' => 'former@edge-cases.test',
                    'role'  => 'admin',
                ]));
        } finally {
            \Config\Services::resetSingle('email');
        }

        // Vérifier que first_access_at est préservé
        $afterReinvite = $db->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->formerMemberId)
            ->get()
            ->getRowArray();

        $this->assertSame('admin', $afterReinvite['role'], 'Le rôle doit être Admin');
        $this->assertSame($originalFirstAccess, $afterReinvite['first_access_at'],
            'first_access_at doit être préservé lors de la réinvitation');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db = db_connect();
        $now = (new \CodeIgniter\I18n\Time())->toDateTimeString();

        // Create users
        $this->ownerId = $this->createUser('owner@edge-cases.test', 'Owner', 'User');
        $this->adminId = $this->createUser('admin@edge-cases.test', 'Admin', 'User');
        $this->existingId = $this->createUser('existing@edge-cases.test', 'Existing', 'User');
        $this->formerMemberId = $this->createUser('former@edge-cases.test', 'Former', 'Member');

        // Create kermesse
        $db->table('kermesses')->insert([
            'created_by'   => $this->ownerId,
            'public_slug'  => 'edge-cases-test',
            'name'         => 'Edge Cases Kermesse',
        ]);
        $this->kermesseId = (int) $db->insertID();

        // Assign Owner
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->ownerId,
            'role'          => UserRoleModel::ROLE_OWNER,
            'first_access_at' => $now,
        ]);

        // Assign Admin (active)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->adminId,
            'role'          => UserRoleModel::ROLE_ADMIN,
            'invited_by'    => $this->ownerId,
            'invited_at'    => $now,
            'first_access_at' => $now,
        ]);

        // Assign former member: active initially, then downgraded to benevole
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $this->formerMemberId,
            'role'          => UserRoleModel::ROLE_ADMIN,
            'invited_by'    => $this->ownerId,
            'invited_at'    => $now,
            'first_access_at' => $now,
        ]);

        // Simulate removal: downgrade to benevole (as per RoleService::removeRole logic)
        $db->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->formerMemberId)
            ->update(['role' => UserRoleModel::ROLE_BENEVOLE]);
    }

    private function createUser(string $email, string $firstName, string $lastName): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]);
        return (int) $db->insertID();
    }

    private function createUserWithRole(string $email, string $role): int
    {
        $userId = $this->createUser($email, 'User', 'Test');
        $db = db_connect();
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'   => $this->kermesseId,
            'user_id'       => $userId,
            'role'          => $role,
            'first_access_at' => (new \CodeIgniter\I18n\Time())->toDateTimeString(),
        ]);
        return $userId;
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function csrf(array $data): array
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();
        return $data;
    }

    private function mockEmailSend(bool $shouldSucceed): void
    {
        $mockEmail = $this->createMock(\CodeIgniter\Email\Email::class);
        $mockEmail->method('send')->willReturn($shouldSucceed);
        \Config\Services::injectMock('email', $mockEmail);
    }
}
