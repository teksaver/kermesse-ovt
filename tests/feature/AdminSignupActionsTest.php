<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SignupModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for admin signup actions — Story 5.10.
 *
 * adminCancel: sets signup status to 'removed', volunteer appears in history section.
 * adminEdit:   updates only the signups table contact fields, never the users table.
 *
 * @internal
 */
final class AdminSignupActionsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $adminId    = 0;
    private int $ownerId    = 0;
    private int $volunteerId = 0;
    private int $kermesseId = 0;
    private int $signupId   = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
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
    // adminCancel — Story 5.10 AC4
    // ------------------------------------------------------------------

    public function testAdminCancelSetsStatusToRemoved(): void
    {
        $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/signups/{$this->signupId}/admin-cancel", [
                csrf_token() => csrf_hash(),
            ]);

        $row = db_connect()
            ->table('signups')
            ->where('id', $this->signupId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(SignupModel::STATUS_REMOVED, $row['status']);
    }

    public function testAdminCancelRedirectsToInscritsTab(): void
    {
        $result = $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/signups/{$this->signupId}/admin-cancel", [
                csrf_token() => csrf_hash(),
            ]);

        $result->assertRedirect();
        $this->assertStringContainsString(
            "kermesse/{$this->kermesseId}#inscrits",
            (string) $result->response()->getHeader('Location'),
        );
    }

    public function testAdminCancelVolunteerAppearsInHistorySection(): void
    {
        // Cancel the signup.
        $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/signups/{$this->signupId}/admin-cancel", [
                csrf_token() => csrf_hash(),
            ]);

        // Get the dashboard and check the history section.
        $result = $this->withSession($this->session($this->adminId))
            ->get("kermesse/{$this->kermesseId}");

        $result->assertStatus(200);
        $result->assertSee('Historique (');
        $result->assertSee('Bénévole');       // volunteer name in history
        $result->assertSee("Supprimé par l'admin");
    }

    public function testAdminCancelStampsLastModifiedByUserId(): void
    {
        $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/signups/{$this->signupId}/admin-cancel", [
                csrf_token() => csrf_hash(),
            ]);

        $row = db_connect()
            ->table('signups')
            ->where('id', $this->signupId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame($this->adminId, (int) $row['last_modified_by_user_id']);
        $this->assertNotNull($row['last_modified_at']);
    }

    // ------------------------------------------------------------------
    // adminEdit — Story 5.10 AC5
    // ------------------------------------------------------------------

    public function testAdminEditUpdatesSignupContactFields(): void
    {
        $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/signups/{$this->signupId}/admin-edit", [
                csrf_token()  => csrf_hash(),
                'first_name'  => 'AliceModif',
                'last_name'   => 'MartinModif',
                'email'       => 'alice.modif@example.com',
                'phone'       => '0699887766',
                'admin_notes' => 'Corrigé par admin',
            ]);

        $signup = db_connect()
            ->table('signups')
            ->where('id', $this->signupId)
            ->get()
            ->getRowArray();

        $this->assertSame('AliceModif',             $signup['first_name']);
        $this->assertSame('MartinModif',            $signup['last_name']);
        $this->assertSame('alice.modif@example.com', $signup['email']);
        $this->assertSame('0699887766',             $signup['phone']);
        $this->assertSame('Corrigé par admin',      $signup['admin_notes']);
    }

    public function testAdminEditNeverMutatesUsersTable(): void
    {
        $beforeUser = db_connect()
            ->table('users')
            ->where('id', $this->volunteerId)
            ->get()
            ->getRowArray();

        $this->withSession($this->session($this->adminId))
            ->post("kermesse/{$this->kermesseId}/signups/{$this->signupId}/admin-edit", [
                csrf_token()  => csrf_hash(),
                'first_name'  => 'NouveauPrenom',
                'last_name'   => 'NouveauNom',
                'email'       => 'nouveau@example.com',
                'phone'       => '0600000001',
                'admin_notes' => '',
            ]);

        $afterUser = db_connect()
            ->table('users')
            ->where('id', $this->volunteerId)
            ->get()
            ->getRowArray();

        // Users table must be untouched.
        $this->assertSame($beforeUser['first_name'], $afterUser['first_name']);
        $this->assertSame($beforeUser['last_name'],  $afterUser['last_name']);
        $this->assertSame($beforeUser['email'],      $afterUser['email']);
        $this->assertSame($beforeUser['phone'],      $afterUser['phone']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['admin@admin-signup-5-10.test',     'Admin',    'Test',    ''],
            ['owner@admin-signup-5-10.test',     'Owner',    'Test',    ''],
            ['benevole@admin-signup-5-10.test',  'Bénévole', 'Inscrit', '0601010101'],
        ] as [$email, $first, $last, $phone]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => $phone,
            ]);
        }

        $rows               = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->adminId      = (int) $rows[0]['id'];
        $this->ownerId      = (int) $rows[1]['id'];
        $this->volunteerId  = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'admin-signup-actions-5-10',
            'name'        => 'Kermesse Admin Actions 5.10',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,    'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->adminId,    'role' => 'admin'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->volunteerId, 'role' => 'benevole'],
        ]);

        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => 'Stand Test 5.10',
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();

        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-15 09:00:00',
            'ends_at'   => '2026-10-15 12:00:00',
            'capacity'  => 3,
            'status'    => 'active',
        ]);
        $slotId = (int) $db->insertID();

        $db->table('signups')->insert([
            'slot_id'    => $slotId,
            'user_id'    => $this->volunteerId,
            'status'     => 'active',
            'deleted_at' => null,
        ]);
        $this->signupId = (int) $db->insertID();
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function setUpTables(): void
    {
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
                capacity   INTEGER  NOT NULL,
                status     TEXT     NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_signups (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id                   INTEGER  NOT NULL,
                user_id                   INTEGER  NOT NULL,
                status                    TEXT     NOT NULL DEFAULT "active",
                deleted_at                DATETIME NULL DEFAULT NULL,
                first_name                TEXT     NULL DEFAULT NULL,
                last_name                 TEXT     NULL DEFAULT NULL,
                email                     TEXT     NULL DEFAULT NULL,
                phone                     TEXT     NULL DEFAULT NULL,
                admin_notes               TEXT     NULL DEFAULT NULL,
                last_modified_by_user_id  INTEGER  NULL DEFAULT NULL,
                last_modified_at          DATETIME NULL DEFAULT NULL,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_email_events (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type     TEXT    NOT NULL,
                status         TEXT    NOT NULL DEFAULT "sent",
                recipient_email TEXT   NOT NULL,
                recipient_hash TEXT    NOT NULL,
                error_message  TEXT,
                metadata       TEXT,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
