<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 4.5 — Inviter des administrateurs et gestionnaires.
 *
 * AC1 : depuis « Gestion des participants », un Owner/Admin invite une personne
 * (par email) comme Admin ou Gestionnaire. Le service crée l'utilisateur si l'email
 * est inconnu, attribue le rôle dans kermesse_user_roles, émet un Magic Link
 * (TokenService) et envoie un email d'invitation tracé dans email_events ; l'UI
 * confirme l'envoi (UX-DR20).
 *
 * Sécurité : seuls Owner/Admin peuvent inviter (RBAC route + formulaire masqué pour
 * Gestionnaire/Bénévole) ; le rôle owner ne peut pas être réattribué par invitation.
 *
 * @internal
 */
final class InviteRoleTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId    = 0;
    private int $adminId    = 0;
    private int $gestionId  = 0;
    private int $benevoleId = 0;
    private int $existingId = 0;
    private int $kermesseId = 0;

    private const INVITE_FORM_MARKER = 'Inviter un administrateur ou un gestionnaire';
    private const NEW_INVITEE_EMAIL  = 'nouveau@invite.test';
    private const EXISTING_EMAIL     = 'existant@invite.test';

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
    // RBAC — visibilité du formulaire (UX-DR16 / NFR5)
    // ------------------------------------------------------------------

    public function testInviteFormVisibleToOwner(): void
    {
        $result = $this->getDashboard($this->ownerId);

        $result->assertStatus(200);
        $result->assertSee(self::INVITE_FORM_MARKER);
    }

    public function testInviteFormVisibleToAdmin(): void
    {
        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertSee(self::INVITE_FORM_MARKER);
    }

    public function testInviteFormHiddenFromGestionnaire(): void
    {
        $result = $this->getDashboard($this->gestionId);

        $result->assertStatus(200);
        // Le Gestionnaire voit la section « Gestion des inscrits » mais PAS le formulaire d'invitation.
        $result->assertSee('Gestion des inscrits');
        $result->assertDontSee(self::INVITE_FORM_MARKER);
    }

    public function testInviteFormHiddenFromBenevole(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertDontSee(self::INVITE_FORM_MARKER);
    }

    // ------------------------------------------------------------------
    // RBAC — soumission interdite à Gestionnaire/Bénévole (filtre role:owner,admin)
    // ------------------------------------------------------------------

    public function testGestionnaireCannotSubmitInvitation(): void
    {
        $result = $this->withSession($this->session($this->gestionId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => self::NEW_INVITEE_EMAIL,
                'role'  => 'admin',
            ]));

        $result->assertStatus(403);
        $this->assertSame(0, $this->roleCountForEmail(self::NEW_INVITEE_EMAIL),
            'Aucun rôle ne doit être créé quand un Gestionnaire tente d\'inviter.');
    }

    public function testBenevoleCannotSubmitInvitation(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => self::NEW_INVITEE_EMAIL,
                'role'  => 'admin',
            ]));

        $result->assertStatus(403);
        $this->assertSame(0, $this->roleCountForEmail(self::NEW_INVITEE_EMAIL));
    }

    // ------------------------------------------------------------------
    // AC1 — Happy path : invitation d'un nouvel email comme Admin
    // ------------------------------------------------------------------

    public function testOwnerInvitesUnknownEmailCreatesUserAssignsRoleTokenAndEvent(): void
    {
        // Envoi déterministe : un mailer qui réussit → la confirmation « Invitation envoyée ».
        $this->mockEmailSend(true);

        try {
            $result = $this->withSession($this->session($this->ownerId))
                ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                    'email' => self::NEW_INVITEE_EMAIL,
                    'role'  => 'admin',
                ]));
        } finally {
            \Config\Services::resetSingle('email');
        }

        $result->assertRedirect();

        // 1) Un compte utilisateur global est créé pour l'email inconnu.
        $user = db_connect()->query(
            'SELECT id FROM db_users WHERE email_hash = ?',
            [hash('sha256', self::NEW_INVITEE_EMAIL)],
        )->getRowArray();
        $this->assertNotNull($user, 'Un compte utilisateur doit être créé pour un email inconnu.');

        // 2) Le rôle demandé est attribué pour cette kermesse, en traçant l'invitant.
        $role = db_connect()->query(
            'SELECT role, invited_by FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?',
            [$this->kermesseId, (int) $user['id']],
        )->getRowArray();
        $this->assertNotNull($role);
        $this->assertSame('admin', $role['role']);
        $this->assertSame($this->ownerId, (int) $role['invited_by']);

        // 3) Un Magic Link (intention kermesse) est émis via TokenService.
        $token = db_connect()->query(
            'SELECT token_type, kermesse_id FROM db_access_tokens WHERE email = ? AND token_type = ?',
            [self::NEW_INVITEE_EMAIL, 'magic_link'],
        )->getRowArray();
        $this->assertNotNull($token, 'Un Magic Link doit être émis pour l\'invité.');
        $this->assertSame($this->kermesseId, (int) $token['kermesse_id']);

        // 4) L'envoi est tracé dans email_events (event role_invitation).
        $event = db_connect()->query(
            'SELECT status FROM db_email_events WHERE event_type = ? AND recipient_email = ?',
            ['role_invitation', self::NEW_INVITEE_EMAIL],
        )->getRowArray();
        $this->assertNotNull($event, 'Un email_event role_invitation doit être enregistré.');
        $this->assertContains($event['status'], ['sent', 'failed']);

        // 5) L'UI confirme l'envoi (UX-DR20).
        $this->assertStringContainsString(
            'Invitation envoyée',
            (string) session()->getFlashdata('invite_success'),
        );
    }

    public function testInviteExistingUserAssignsRoleWithoutDuplicatingAccount(): void
    {
        $result = $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => self::EXISTING_EMAIL,
                'role'  => 'gestionnaire',
            ]));

        $result->assertRedirect();

        // L'utilisateur existant n'est pas dupliqué.
        $count = (int) db_connect()->query(
            'SELECT COUNT(*) AS cnt FROM db_users WHERE email_hash = ?',
            [hash('sha256', self::EXISTING_EMAIL)],
        )->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'Aucun doublon de compte ne doit être créé.');

        // Le rôle gestionnaire est attribué sur cette kermesse.
        $role = db_connect()->query(
            'SELECT role FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?',
            [$this->kermesseId, $this->existingId],
        )->getRowArray();
        $this->assertNotNull($role);
        $this->assertSame('gestionnaire', $role['role']);
    }

    public function testReInvitingMemberUpdatesRoleIdempotently(): void
    {
        // Le Bénévole a déjà un rôle ; l'inviter comme gestionnaire doit METTRE À JOUR la
        // ligne unique (kermesse_id, user_id) sans lever d'erreur de doublon (idempotence).
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => 'benevole@invite.test',
                'role'  => 'gestionnaire',
            ]));

        $result->assertRedirect();

        $rows = db_connect()->query(
            'SELECT role FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?',
            [$this->kermesseId, $this->benevoleId],
        )->getResultArray();
        $this->assertCount(1, $rows, 'Un seul rôle par membre (contrainte uq_role_per_kermesse).');
        $this->assertSame('gestionnaire', $rows[0]['role']);
    }

    public function testEmailDeliveryFailureWarnsButStillAssignsRole(): void
    {
        // Mailer en échec : le rôle DOIT quand même être attribué (l'envoi ne bloque pas),
        // mais l'UI signale honnêtement l'échec d'envoi au lieu de confirmer « envoyée ».
        $this->mockEmailSend(false);

        try {
            $result = $this->withSession($this->session($this->ownerId))
                ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                    'email' => self::NEW_INVITEE_EMAIL,
                    'role'  => 'admin',
                ]));
        } finally {
            \Config\Services::resetSingle('email');
        }

        $result->assertRedirect();
        // UI honnête : avertissement, pas de fausse confirmation d'envoi.
        $this->assertStringContainsString(
            "n'a pas pu être envoyé",
            (string) session()->getFlashdata('invite_warning'),
        );

        // Le rôle est bien attribué malgré l'échec d'envoi.
        $this->assertSame(1, $this->roleCountForEmail(self::NEW_INVITEE_EMAIL));

        // L'échec est tracé dans email_events (status failed).
        $event = db_connect()->query(
            'SELECT status FROM db_email_events WHERE event_type = ? AND recipient_email = ?',
            ['role_invitation', self::NEW_INVITEE_EMAIL],
        )->getRowArray();
        $this->assertNotNull($event);
        $this->assertSame('failed', $event['status']);
    }

    // ------------------------------------------------------------------
    // Validations
    // ------------------------------------------------------------------

    public function testInvalidEmailIsRejected(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => 'pas-un-email',
                'role'  => 'admin',
            ]));

        $result->assertRedirect();
        $result->assertSessionHas('invite_error');
        // Aucun compte/rôle créé pour une saisie invalide.
        $this->assertSame(0, $this->userCountForEmail('pas-un-email'));
    }

    public function testInvalidRoleIsRejected(): void
    {
        // Tenter d'attribuer un rôle hors liste (owner) doit échouer à la validation.
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => self::NEW_INVITEE_EMAIL,
                'role'  => 'owner',
            ]));

        $result->assertRedirect();
        $result->assertSessionHas('invite_error');
        $this->assertSame(0, $this->roleCountForEmail(self::NEW_INVITEE_EMAIL));
    }

    // ------------------------------------------------------------------
    // Sécurité — le rôle owner ne peut pas être réattribué par invitation
    // ------------------------------------------------------------------

    public function testCannotReassignOwnerThroughInvitation(): void
    {
        $result = $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/invitations", $this->csrf([
                'email' => 'owner@invite.test',
                'role'  => 'admin',
            ]));

        $result->assertRedirect();
        $result->assertSessionHas('invite_error');

        // Le propriétaire conserve son rôle owner (pas de rétrogradation silencieuse).
        $role = db_connect()->query(
            'SELECT role FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?',
            [$this->kermesseId, $this->ownerId],
        )->getRowArray();
        $this->assertSame('owner', $role['role']);
    }

    // ------------------------------------------------------------------
    // Suppression d'un membre de l'équipe (removeRole)
    // ------------------------------------------------------------------

    public function testRemoveActiveMemberWithoutSignupsDowngradesToBenevole(): void
    {
        // Story 5.8: un membre actif (invited_at IS NULL) est toujours rétrogradé en bénévole,
        // même sans inscriptions, afin qu'il garde la kermesse visible dans "Mes participations"
        // et puisse se retirer lui-même via Story 5.9.
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/team/{$this->adminId}/delete", $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame('benevole', $this->roleForUser($this->adminId),
            'Un membre actif sans inscriptions doit être rétrogradé en bénévole (pas supprimé).');
    }

    public function testRemoveAdminWithActiveSignupsDowngradesToBenevole(): void
    {
        $this->insertSignupForUser($this->adminId);

        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/team/{$this->adminId}/delete", $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame('benevole', $this->roleForUser($this->adminId),
            'Un admin avec des inscriptions actives doit être rétrogradé en bénévole, pas supprimé.');
    }

    public function testRemoveGestionnaireWithActiveSignupsDowngradesToBenevole(): void
    {
        $this->insertSignupForUser($this->gestionId);

        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/team/{$this->gestionId}/delete", $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame('benevole', $this->roleForUser($this->gestionId),
            'Un gestionnaire avec des inscriptions actives doit être rétrogradé en bénévole.');
    }

    // ------------------------------------------------------------------
    // Story 5.8 — Révocation par un Admin + flash message
    // ------------------------------------------------------------------

    public function testAdminCanRevokeGestionnaire(): void
    {
        // AC4 Story 5.8 (branche Gestionnaire) : un Admin peut révoquer un Gestionnaire.
        $result = $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/team/{$this->gestionId}/delete", $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame('benevole', $this->roleForUser($this->gestionId),
            'Un Admin doit pouvoir révoquer un Gestionnaire.');
    }

    public function testAdminCanRevokeAnotherAdmin(): void
    {
        // AC4 Story 5.8 (branche Admin→Admin) : un Admin peut révoquer un autre Admin.
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => 'admin2@invite.test',
            'email_hash' => hash('sha256', 'admin2@invite.test'),
            'first_name' => 'Admin2', 'last_name' => 'Test', 'phone' => '',
        ]);
        $admin2Id = (int) $db->insertID();
        $db->table('kermesse_user_roles')->insert([
            'kermesse_id' => $this->kermesseId, 'user_id' => $admin2Id, 'role' => 'admin',
        ]);

        $result = $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/team/{$admin2Id}/delete", $this->csrf([]));

        $result->assertRedirect();
        $this->assertSame('benevole', $this->roleForUser($admin2Id),
            'Un Admin doit pouvoir révoquer un autre Admin (symétrie avec l\'invitation Story 5.5).');
    }

    public function testRevocationFlashMessageIsCorrect(): void
    {
        $result = $this->withSession($this->session($this->ownerId))
            ->post("kermesse/{$this->kermesseId}/team/{$this->adminId}/delete", $this->csrf([]));

        $result->assertRedirect();
        $this->assertStringContainsString(
            'révoqué',
            (string) session()->getFlashdata('invite_success'),
            'Le message de confirmation doit mentionner la révocation.',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function getDashboard(int $userId): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession($this->session($userId))->get("kermesse/{$this->kermesseId}");
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function csrf(array $data): array
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $data;
    }

    /**
     * Force a deterministic mailer outcome so flash assertions don't depend on a real MTA.
     * Caller must reset the singleton (\Config\Services::resetSingle('email')) after the request.
     */
    private function mockEmailSend(bool $sent): void
    {
        $mockEmail = $this->createMock(\CodeIgniter\Email\Email::class);
        $mockEmail->method('send')->willReturn($sent);
        \Config\Services::injectMock('email', $mockEmail);
    }

    private function roleForUser(int $userId): ?string
    {
        $row = db_connect()->query(
            'SELECT role FROM db_kermesse_user_roles WHERE kermesse_id = ? AND user_id = ?',
            [$this->kermesseId, $userId],
        )->getRowArray();

        return $row !== null ? (string) $row['role'] : null;
    }

    private function insertSignupForUser(int $userId): void
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand test',
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

        $db->table('signups')->insert([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'status'  => 'active',
        ]);
    }

    private function userCountForEmail(string $email): int
    {
        return (int) db_connect()->query(
            'SELECT COUNT(*) AS cnt FROM db_users WHERE email_hash = ?',
            [hash('sha256', $email)],
        )->getRowArray()['cnt'];
    }

    private function roleCountForEmail(string $email): int
    {
        return (int) db_connect()->query(
            'SELECT COUNT(*) AS cnt FROM db_kermesse_user_roles kur
             JOIN db_users u ON u.id = kur.user_id
             WHERE u.email_hash = ? AND kur.kermesse_id = ?',
            [hash('sha256', $email), $this->kermesseId],
        )->getRowArray()['cnt'];
    }

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@invite.test', 'Owner', 'Test'],
            ['admin@invite.test', 'Admin', 'Test'],
            ['gestion@invite.test', 'Gestion', 'Test'],
            ['benevole@invite.test', 'Benevole', 'Test'],
            [self::EXISTING_EMAIL, 'Existant', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '',
            ]);
        }

        $rows             = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId    = (int) $rows[0]['id'];
        $this->adminId    = (int) $rows[1]['id'];
        $this->gestionId  = (int) $rows[2]['id'];
        $this->benevoleId = (int) $rows[3]['id'];
        $this->existingId = (int) $rows[4]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'invite-role-45',
            'name'        => 'Kermesse Invitation 4.5',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        // L'invité « existant » n'a PAS encore de rôle sur cette kermesse (cas « email connu »).
        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,    'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->adminId,    'role' => 'admin'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->gestionId,  'role' => 'gestionnaire'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleId, 'role' => 'benevole'],
        ]);
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
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        // Reproduit la contrainte d'unicité de production (uq_role_per_kermesse) pour que le
        // test détecte tout doublon de rôle au lieu de le masquer (un seul rôle par membre).
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_role_per_kermesse ON db_kermesse_user_roles (kermesse_id, user_id)');
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
