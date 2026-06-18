<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\RoleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for RoleService::removeRole — Story 5.8.
 *
 * Scénarios :
 *   - invitation en attente sans inscriptions actives → la ligne est supprimée
 *   - invitation en attente avec inscriptions actives → rétrogradée en bénévole
 *   - membre actif (non en attente) sans inscriptions → rétrogradé en bénévole
 *   - membre actif avec inscriptions actives → rétrogradé en bénévole
 *   - rôle Owner → aucune opération
 *
 * @internal
 */
final class RoleServiceRemoveRoleTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // T4.1 — Invitation en attente, sans inscriptions actives → suppression
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithoutSignupsDeletesRow(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: true);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertNull($this->roleForUser($kermesseId, $userId),
            'Une invitation en attente sans inscriptions doit être supprimée (annulation de l\'invitation).');
    }

    // ------------------------------------------------------------------
    // T4.2 — Invitation en attente, avec inscriptions actives → bénévole
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithSignupsDowngradesToBenevole(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: true);
        $this->insertActiveSignup($kermesseId, $userId);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Une invitation en attente avec des inscriptions actives doit être rétrogradée en bénévole.');
    }

    // ------------------------------------------------------------------
    // T4.3 — Membre actif (pas en attente), sans inscriptions → bénévole
    // ------------------------------------------------------------------

    public function testRemoveRoleActiveMemberWithoutSignupsDowngradesToBenevole(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: false);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Un membre actif sans inscriptions doit être rétrogradé en bénévole (pas supprimé).');
    }

    // ------------------------------------------------------------------
    // T4.4 — Membre actif avec inscriptions actives → bénévole
    // ------------------------------------------------------------------

    public function testRemoveRoleActiveMemberWithSignupsDowngradesToBenevole(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: false);
        $this->insertActiveSignup($kermesseId, $userId);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Un membre actif avec des inscriptions actives doit être rétrogradé en bénévole.');
    }

    // ------------------------------------------------------------------
    // T4.5 — Rôle Owner → aucune opération, retourne false
    // ------------------------------------------------------------------

    public function testRemoveRoleOwnerIsNoOp(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: false, role: 'owner');

        $result = $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertFalse($result, 'removeRole() doit retourner false pour un Owner (no-op).');
        $this->assertSame('owner', $this->roleForUser($kermesseId, $userId),
            'Le rôle Owner ne peut pas être révoqué (unauthorized_role).');
    }

    // ------------------------------------------------------------------
    // T4.6 — Utilisateur en cours de confirmation (accepted_at IS NOT NULL,
    //         first_access_at IS NULL) → rétrogradé en bénévole, pas supprimé
    // ------------------------------------------------------------------

    public function testRemoveRoleMidConfirmationDowngradesToBenevole(): void
    {
        // Cas Edge[2] review : l'utilisateur a cliqué son magic link (accepted_at positionné)
        // mais n'a pas encore chargé le dashboard (first_access_at IS NULL).
        // Il ne doit PAS être supprimé — il est en cours de confirmation.
        [$kermesseId, $userId] = $this->fixture(pendingInvite: false);
        // Patch : remplacer la ligne avec l'état "mid-confirmation"
        db_connect()->table('kermesse_user_roles')
            ->where('kermesse_id', $kermesseId)->where('user_id', $userId)
            ->update([
                'invited_at'      => date('Y-m-d H:i:s'),
                'accepted_at'     => date('Y-m-d H:i:s'),
                'first_access_at' => null,
            ]);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Un utilisateur en cours de confirmation (accepted_at IS NOT NULL) doit être rétrogradé, pas supprimé.');
    }

    // ------------------------------------------------------------------
    // T4.7 — Membre invité et ayant accédé (invited_at IS NOT NULL,
    //         first_access_at IS NOT NULL) → rétrogradé en bénévole
    // ------------------------------------------------------------------

    public function testRemoveRoleInvitedAndAccessedMemberDowngradesToBenevole(): void
    {
        // invited_at IS NOT NULL + first_access_at IS NOT NULL = membre actif qui a été invité.
        // isPendingInvite = false → downgrade to benevole.
        [$kermesseId, $userId] = $this->fixture(pendingInvite: false);
        db_connect()->table('kermesse_user_roles')
            ->where('kermesse_id', $kermesseId)->where('user_id', $userId)
            ->update([
                'invited_at'      => '2026-01-01 08:00:00',
                'first_access_at' => '2026-01-02 10:00:00',
            ]);

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Un membre invité ayant accédé au dashboard doit être rétrogradé en bénévole.');
    }

    // ------------------------------------------------------------------
    // T4.8 — Invitation en attente avec une inscription INACTIVE (cancelled)
    //         → l'inscription ne compte pas → la ligne est supprimée.
    //         Verrouille l'alignement de hasActiveSlotSignups sur la définition
    //         canonique (status NOT IN INACTIVE_STATUSES), pas l'égalité 'active'.
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithCancelledSignupDeletesRow(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: true);
        $this->insertSignup($kermesseId, $userId, 'cancelled');

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertNull($this->roleForUser($kermesseId, $userId),
            'Une inscription inactive (cancelled) ne doit pas être comptée comme active : la ligne doit être supprimée.');
    }

    // ------------------------------------------------------------------
    // T4.9 — Verrou d'alignement : un statut actif-ish AUTRE que 'active'
    //         (ex. un futur 'confirmed') DOIT compter comme actif.
    //         Avec l'ancienne égalité `= 'active'` ce test échouerait (ligne
    //         supprimée) ; avec `whereNotIn(INACTIVE_STATUSES)` il passe.
    // ------------------------------------------------------------------

    public function testRemoveRolePendingInviteWithNonInactiveStatusDowngradesToBenevole(): void
    {
        [$kermesseId, $userId] = $this->fixture(pendingInvite: true);
        // 'confirmed' n'existe pas dans l'enum actuel : on simule un futur statut
        // actif-ish pour verrouiller la définition partagée (NOT IN INACTIVE_STATUSES).
        $this->insertSignup($kermesseId, $userId, 'confirmed');

        $this->makeService()->removeRole($kermesseId, $userId, 0);

        $this->assertSame('benevole', $this->roleForUser($kermesseId, $userId),
            'Tout statut hors INACTIVE_STATUSES doit compter comme actif : rétrogradation, pas suppression.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeService(): RoleService
    {
        return new RoleService(model(UserRoleModel::class), model(UserModel::class));
    }

    /** @return array{int, int} [kermesseId, userId] */
    private function fixture(bool $pendingInvite, string $role = 'admin'): array
    {
        $db = db_connect();

        $db->table('users')->insert([
            'email'      => 'target-58@kermesse.test',
            'email_hash' => hash('sha256', 'target-58@kermesse.test'),
        ]);
        $userId = (int) $db->insertID();

        $db->table('users')->insert([
            'email'      => 'owner-58@kermesse.test',
            'email_hash' => hash('sha256', 'owner-58@kermesse.test'),
        ]);
        $ownerId = (int) $db->insertID();

        $db->table('kermesses')->insert([
            'created_by'  => $ownerId,
            'public_slug' => 'remove-role-58-' . uniqid(),
            'name'        => 'Kermesse Remove Role 5.8',
            'status'      => 'open',
        ]);
        $kermesseId = (int) $db->insertID();

        $roleData = [
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => $role,
        ];
        if ($pendingInvite) {
            // invited_at IS NOT NULL, first_access_at IS NULL → invitation en attente
            $roleData['invited_at']      = date('Y-m-d H:i:s');
            $roleData['first_access_at'] = null;
        } else {
            // first_access_at IS NOT NULL → membre actif (a accédé au tableau de bord)
            $roleData['invited_at']      = null;
            $roleData['first_access_at'] = '2026-01-01 10:00:00';
        }
        $db->table('kermesse_user_roles')->insert($roleData);

        return [$kermesseId, $userId];
    }

    private function insertActiveSignup(int $kermesseId, int $userId): void
    {
        $this->insertSignup($kermesseId, $userId, 'active');
    }

    private function insertSignup(int $kermesseId, int $userId, string $status): void
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $kermesseId,
            'name'          => 'Stand test 5.8',
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
            default     => [],  // 'active', 'confirmed', or any other active-ish state
        };
        $db->table('signups')->insert($row);
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
                deleted_at    DATETIME NULL DEFAULT NULL,
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
                deleted_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            DROP TABLE IF EXISTS db_signups;
            CREATE TABLE IF NOT EXISTS db_signups (
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
