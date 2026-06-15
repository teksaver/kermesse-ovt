<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 5.2 — Navigation par 4 onglets et suivi d'accès par kermesse.
 *
 * Couvre :
 * - AC1 : les onglets autorisés sont rendus ; les non-autorisés sont ABSENTS du DOM.
 * - AC2 : les colonnes first_access_at / last_access_at existent dans la table.
 * - AC3 : first_access_at est positionné au 1er chargement ; last_access_at mis à jour à chaque visite.
 *
 * @internal
 */
final class DashboardTabNavigationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId     = 0;
    private int $gestionId   = 0;
    private int $benevoleId  = 0;
    private int $kermesseId  = 0;

    // Labels des boutons d'onglet (côté serveur — absents du DOM si non autorisé).
    private const TAB_MODIFICATION    = 'Modification';
    private const TAB_INSCRITS        = 'Gestion des inscrits';
    private const TAB_EQUIPE          = 'Équipe';
    private const TAB_PARTICIPATIONS  = 'Mes participations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

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
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                status     TEXT    NOT NULL DEFAULT "active",
                deleted_at DATETIME NULL DEFAULT NULL,
                last_modified_by_user_id INTEGER NULL DEFAULT NULL,
                last_modified_at         DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_tokens (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                token_hash TEXT    NOT NULL UNIQUE,
                scope      TEXT    NOT NULL,
                kermesse_id INTEGER NULL DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_email_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NULL DEFAULT NULL,
                email      TEXT    NOT NULL,
                type       TEXT    NOT NULL,
                status     TEXT    NOT NULL DEFAULT "sent",
                error_message TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@tab-5-2.test',    'Owner', 'Tab'],
            ['gestion@tab-5-2.test',  'Gestion', 'Tab'],
            ['benevole@tab-5-2.test', 'Benevole', 'Tab'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '',
            ]);
        }

        $rows            = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId   = (int) $rows[0]['id'];
        $this->gestionId = (int) $rows[1]['id'];
        $this->benevoleId = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'tab-nav-test-52',
            'name'        => 'Kermesse Tab 5.2',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,    'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->gestionId,  'role' => 'gestionnaire'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleId, 'role' => 'benevole'],
        ]);
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function getDashboard(int $userId): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession($this->session($userId))
            ->get("kermesse/{$this->kermesseId}");
    }

    private function fetchRoleRow(int $userId): ?array
    {
        return db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();
    }

    // ------------------------------------------------------------------
    // AC1 — Onglets autorisés présents / non autorisés absents
    // ------------------------------------------------------------------

    public function testOwnerSeesAllFourTabs(): void
    {
        $result = $this->getDashboard($this->ownerId);
        $result->assertStatus(200);

        $result->assertSee(self::TAB_MODIFICATION);
        $result->assertSee(self::TAB_INSCRITS);
        $result->assertSee(self::TAB_EQUIPE);
        $result->assertSee(self::TAB_PARTICIPATIONS);
    }

    public function testGestionnaireSeesOnlyInscritsAndParticipationsTabs(): void
    {
        $result = $this->getDashboard($this->gestionId);
        $result->assertStatus(200);

        // Onglets autorisés pour Gestionnaire.
        $result->assertSee(self::TAB_INSCRITS);
        $result->assertSee(self::TAB_PARTICIPATIONS);

        // Onglets non autorisés : ABSENTS du DOM (UX-DR16).
        // Note: le bouton "Modification" est distinct de la section h2 "Modification de la kermesse"
        // (celle-ci est aussi absente car dans if($canModify)).
        $result->assertDontSee('data-tab="modification"');
        $result->assertDontSee('data-tab="equipe"');
    }

    public function testBenevoleSeesOnlyParticipationsTab(): void
    {
        $result = $this->getDashboard($this->benevoleId);
        $result->assertStatus(200);

        $result->assertSee(self::TAB_PARTICIPATIONS);

        // Onglets non autorisés absents.
        $result->assertDontSee('data-tab="modification"');
        $result->assertDontSee('data-tab="inscrits"');
        $result->assertDontSee('data-tab="equipe"');
    }

    // ------------------------------------------------------------------
    // AC3 — Suivi d'accès : first_access_at et last_access_at
    // ------------------------------------------------------------------

    public function testFirstVisitSetsFirstAccessAt(): void
    {
        // Pré-condition : first_access_at est NULL avant la visite.
        $row = $this->fetchRoleRow($this->ownerId);
        $this->assertNull($row['first_access_at'], 'first_access_at doit être NULL avant la 1ère visite');

        $before = date('Y-m-d H:i:s');
        $this->getDashboard($this->ownerId)->assertStatus(200);
        $after  = date('Y-m-d H:i:s');

        $row = $this->fetchRoleRow($this->ownerId);
        $this->assertNotNull($row['first_access_at'], 'first_access_at doit être positionné après la 1ère visite');
        $this->assertGreaterThanOrEqual($before, $row['first_access_at']);
        $this->assertLessThanOrEqual($after,  $row['first_access_at']);
    }

    public function testFirstAccessAtIsNotOverwrittenOnSubsequentVisit(): void
    {
        $originalFirst = '2026-01-15 10:00:00';
        db_connect()
            ->table('kermesse_user_roles')
            ->where('kermesse_id', $this->kermesseId)
            ->where('user_id', $this->ownerId)
            ->update(['first_access_at' => $originalFirst]);

        $this->getDashboard($this->ownerId)->assertStatus(200);

        $row = $this->fetchRoleRow($this->ownerId);
        $this->assertSame(
            $originalFirst,
            $row['first_access_at'],
            'first_access_at ne doit pas être modifié lors des visites suivantes',
        );
    }

    public function testLastAccessAtIsUpdatedOnEveryVisit(): void
    {
        $before = date('Y-m-d H:i:s');
        $this->getDashboard($this->ownerId)->assertStatus(200);
        $after  = date('Y-m-d H:i:s');

        $row = $this->fetchRoleRow($this->ownerId);
        $this->assertNotNull($row['last_access_at'], 'last_access_at doit être mis à jour à chaque visite');
        $this->assertGreaterThanOrEqual($before, $row['last_access_at']);
        $this->assertLessThanOrEqual($after,  $row['last_access_at']);
    }
}
