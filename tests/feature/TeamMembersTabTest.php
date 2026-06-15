<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserRoleModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 5.5 — Onglet « Équipe » : tests fonctionnels des 3 ACs.
 *
 * AC1 : Owner/Admin voient les membres actifs groupés par rôle
 *       ET les invitations en attente dans une section distincte.
 * AC2 : Inviter un email qui possède déjà le même rôle → already_has_role.
 * AC3 : Réinviter un ancien membre (first_access_at non null, role = benevole)
 *       restaure son rôle sans remettre first_access_at à null.
 *
 * PII : la section « Équipe » est absente du HTML pour les non-Owner/Admin.
 *
 * @internal
 */
final class TeamMembersTabTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private int $ownerId       = 0;
    private int $adminId       = 0;
    private int $gestionId     = 0;
    private int $benevoleId    = 0;
    private int $pendingId     = 0;
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
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC1 — Membres actifs groupés par rôle + section « En attente »
    // ------------------------------------------------------------------

    public function testOwnerSeesActiveMembersGroupedByRole(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);

        // L'onglet Équipe doit être présent pour Owner
        $result->assertSee('Gestion de l\'équipe d\'organisation');

        // Les sections de groupement par rôle doivent apparaître
        $result->assertSee('Propriétaire');
        $result->assertSee('Administrateurs');
        $result->assertSee('Gestionnaires');

        // Les membres actifs doivent être visibles
        $result->assertSee('Owner User');
        $result->assertSee('Active Admin');
        $result->assertSee('Active Gestion');
    }

    public function testAdminSeesActiveMembersGroupedByRole(): void
    {
        $result = $this->withSession($this->session($this->adminId))
            ->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);
        $result->assertSee('Gestion de l\'équipe d\'organisation');
        $result->assertSee('Propriétaire');
        $result->assertSee('Administrateurs');
    }

    public function testPendingInvitationsAppearInSeparateSection(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);

        // La section « En attente » doit apparaître
        $result->assertSee('Invitations en attente');

        // Le membre en attente doit être dans cette section
        $result->assertSee('Pending User');
        $result->assertSee('Invitation envoyée');
    }

    // ------------------------------------------------------------------
    // AC2 — Doublon de rôle → already_has_role
    // ------------------------------------------------------------------

    public function testInviteExistingAdminAsAdminShowsAlreadyHasRoleMessage(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => 'active-admin@team-tab.test',
                'role'  => 'admin',
            ]));

        $result->assertRedirect();

        // Le message already_has_role doit apparaître dans invite_error
        $errorMsg = session()->getFlashdata('invite_error');
        $this->assertNotNull($errorMsg, 'Un message d\'erreur doit être présent dans invite_error');
        $this->assertStringContainsString('administrateur', (string) $errorMsg,
            'Le message doit mentionner le rôle en conflit');

        // Aucun doublon de rôle ne doit être créé
        $count = db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->adminId)
            ->countAllResults();
        $this->assertSame(1, $count, 'Aucun doublon ne doit être créé');
    }

    public function testInviteExistingGestionnaireAsGestionnaireShowsAlreadyHasRoleMessage(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => 'active-gestion@team-tab.test',
                'role'  => 'gestionnaire',
            ]));

        $result->assertRedirect();

        $errorMsg = session()->getFlashdata('invite_error');
        $this->assertNotNull($errorMsg);
        $this->assertStringContainsString('gestionnaire', (string) $errorMsg,
            'Le message doit mentionner gestionnaire pour ce rôle en conflit');
    }

    // ------------------------------------------------------------------
    // AC3 — Réinvitation d'un ancien membre préserve first_access_at
    // ------------------------------------------------------------------

    public function testReinvitingFormerMemberPreservesFirstAccessAtAndDoesNotAppearPending(): void
    {
        $db = db_connect();

        // Vérifier l'état initial : former member = benevole, first_access_at défini
        $before = $db->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->formerMemberId)
            ->get()->getRowArray();

        $originalFirstAccess = $before['first_access_at'];
        $this->assertNotNull($originalFirstAccess);
        $this->assertSame('benevole', $before['role']);

        // Réinviter comme Admin
        $this->mockEmailSend(true);
        try {
            $this->withSession($this->session($this->ownerId))
                ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                    'email' => 'former-member@team-tab.test',
                    'role'  => 'admin',
                ]));
        } finally {
            \Config\Services::resetSingle('email');
        }

        $after = $db->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->formerMemberId)
            ->get()->getRowArray();

        // Le rôle doit être mis à jour
        $this->assertSame('admin', $after['role'], 'Le rôle doit être Admin après réinvitation');

        // first_access_at doit être préservé (pas remis à null)
        $this->assertSame($originalFirstAccess, $after['first_access_at'],
            'first_access_at doit être préservé lors d\'une réinvitation');

        // L'ancien membre réinvité ne doit PAS apparaître dans « En attente »
        // car first_access_at est défini → il est « actif »
        $response = $this->withSession($this->session($this->ownerId))
            ->get("kermesse/{$this->kermesseId}");

        // Il doit apparaître dans la section des membres actifs, pas « En attente »
        $html = (string) $response->getBody();

        // Vérifier que l'email du former member est dans le HTML (il est visible dans actifs)
        $this->assertStringContainsString('former-member@team-tab.test', $html);

        // Le nom doit apparaître une seule fois (dans actifs, pas dans pending aussi)
        $occurrences = substr_count($html, 'Former Member');
        $this->assertSame(1, $occurrences, 'Former Member ne doit apparaître qu\'une seule fois (section actifs)');
    }

    // ------------------------------------------------------------------
    // PII — L'onglet Équipe est invisible pour Gestionnaire et Bénévole
    // ------------------------------------------------------------------

    public function testGestionnaireDoesNotSeeTeamTab(): void
    {
        $result = $this->withSession($this->session($this->gestionId))
            ->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);

        // Le Gestionnaire ne doit PAS voir l'onglet Équipe
        $result->assertDontSee('Gestion de l\'équipe d\'organisation');
        // Ni les emails des membres de l'équipe (PII)
        $result->assertDontSee('owner@team-tab.test');
        $result->assertDontSee('active-admin@team-tab.test');
    }

    public function testBenevoleDoesNotSeeTeamTab(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))
            ->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);

        $result->assertDontSee('Gestion de l\'équipe d\'organisation');
        $result->assertDontSee('owner@team-tab.test');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db  = db_connect();
        $now = (new \CodeIgniter\I18n\Time())->toDateTimeString();

        // Users
        $this->ownerId        = $this->createUser($db, 'owner@team-tab.test',          'Owner',   'User');
        $this->adminId        = $this->createUser($db, 'active-admin@team-tab.test',   'Active',  'Admin');
        $this->gestionId      = $this->createUser($db, 'active-gestion@team-tab.test', 'Active',  'Gestion');
        $this->benevoleId     = $this->createUser($db, 'benevole@team-tab.test',       'Bénévole', 'User');
        $this->pendingId      = $this->createUser($db, 'pending@team-tab.test',        'Pending', 'User');
        $this->formerMemberId = $this->createUser($db, 'former-member@team-tab.test',  'Former',  'Member');

        // Kermesse
        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'team-tab-55',
            'name'        => 'Kermesse Team Tab 5.5',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        // Owner actif
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'    => $this->kermesseId,
            'user_id'        => $this->ownerId,
            'role'           => UserRoleModel::ROLE_OWNER,
            'first_access_at' => $now,
            'last_access_at'  => $now,
        ]);

        // Admin actif (first_access_at set)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'    => $this->kermesseId,
            'user_id'        => $this->adminId,
            'role'           => UserRoleModel::ROLE_ADMIN,
            'invited_by'     => $this->ownerId,
            'invited_at'     => $now,
            'first_access_at' => $now,
            'last_access_at'  => $now,
        ]);

        // Gestionnaire actif (first_access_at set)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'    => $this->kermesseId,
            'user_id'        => $this->gestionId,
            'role'           => UserRoleModel::ROLE_GESTIONNAIRE,
            'invited_by'     => $this->ownerId,
            'invited_at'     => $now,
            'first_access_at' => $now,
        ]);

        // Bénévole
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id' => $this->kermesseId,
            'user_id'     => $this->benevoleId,
            'role'        => UserRoleModel::ROLE_BENEVOLE,
        ]);

        // En attente : invited_at défini, first_access_at null
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'    => $this->kermesseId,
            'user_id'        => $this->pendingId,
            'role'           => UserRoleModel::ROLE_ADMIN,
            'invited_by'     => $this->ownerId,
            'invited_at'     => $now,
            'first_access_at' => null,
        ]);

        // Ancien membre : first_access_at défini mais downgrade en benevole (simulate removeRole)
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id'    => $this->kermesseId,
            'user_id'        => $this->formerMemberId,
            'role'           => UserRoleModel::ROLE_BENEVOLE,
            'invited_by'     => $this->ownerId,
            'invited_at'     => $now,
            'first_access_at' => $now,
        ]);
    }

    private function createUser(\CodeIgniter\Database\BaseConnection $db, string $email, string $firstName, string $lastName): int
    {
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => '',
        ]);
        return (int) $db->insertID();
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
                capacity   INTEGER  NOT NULL,
                status     TEXT     NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_signups (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                status     TEXT    NOT NULL DEFAULT "active",
                deleted_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    }
}
