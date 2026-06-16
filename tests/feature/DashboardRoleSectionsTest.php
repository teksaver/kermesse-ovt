<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 4.1 — Afficher le tableau de bord interne par rôle.
 *
 * Couvre l'AC1 (rendu conditionnel des sections selon le rôle, côté serveur) :
 * - "Modification"            → Owner / Admin uniquement
 * - "Gestion des participants"→ Owner / Admin / Gestionnaire
 * - "Mes participations"      → tout rôle (y compris Bénévole)
 * - Une section non autorisée est ABSENTE du HTML, pas seulement désactivée (UX-DR16).
 *
 * @internal
 */
final class DashboardRoleSectionsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId    = 0;
    private int $adminId    = 0;
    private int $gestionId  = 0;
    private int $benevoleId = 0;
    private int $strangerId = 0;
    private int $kermesseId = 0;

    // Marqueurs stables de chaque section dans le HTML rendu.
    private const MARKER_MODIFICATION = 'Gestion des stands et des créneaux';
    private const MARKER_PARTICIPANTS = 'Gestion des inscrits';
    private const MARKER_TEAM         = 'Gestion de l\'équipe d\'organisation';
    private const MARKER_MY_SIGNUPS   = 'Mes participations';
    private const MARKER_STAND_DATA   = 'Stand Test 4.1';

    // Outils individuels de la section « Modification ». UX-DR16 exige que TOUS
    // soient absents (pas seulement désactivés) pour Gestionnaire/Bénévole.
    //
    // Note Story 4.4 : MARKER_STAND_DATA ne fait PLUS partie de cette liste. Le nom
    // d'un stand apparaît désormais aussi dans la section « Gestion des participants »
    // (visible Owner/Admin/Gestionnaire) ; ce n'est donc plus un marqueur exclusif de
    // « Modification ». Seuls les outils d'édition ci-dessous restent réservés à
    // Owner/Admin.
    private const MODIFICATION_TOOLS = [
        'Gestion des stands et des créneaux',            // titre de section
        'Ajouter un stand',        // gestion des stands
        'Modifier la kermesse',    // édition des caractéristiques (bouton + modale)
        'Fermer les inscriptions', // action lifecycle (kermesse fixée à « open »)
    ];

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
    // Helpers
    // ------------------------------------------------------------------

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
        $db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_stands_active_name ON db_stands (kermesse_id, name) WHERE status = "active"');
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
    }

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@dash-4-1.test', 'Owner', 'Test'],
            ['admin@dash-4-1.test', 'Admin', 'Test'],
            ['gestion@dash-4-1.test', 'Gestion', 'Test'],
            ['benevole@dash-4-1.test', 'Benevole', 'Test'],
            ['stranger@dash-4-1.test', 'Stranger', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '', 
            ]);
        }

        $rows = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId    = (int) $rows[0]['id'];
        $this->adminId    = (int) $rows[1]['id'];
        $this->gestionId  = (int) $rows[2]['id'];
        $this->benevoleId = (int) $rows[3]['id'];
        $this->strangerId = (int) $rows[4]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'test-dashboard-41',
            'name'        => 'Kermesse Dashboard 4.1',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,    'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->adminId,    'role' => 'admin'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->gestionId,  'role' => 'gestionnaire'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleId, 'role' => 'benevole'],
        ]);

        // Un stand actif avec un créneau : sert à prouver la minimisation des
        // données (ses données ne doivent jamais fuiter aux rôles non-Modification).
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => self::MARKER_STAND_DATA,
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-10 09:00:00',
            'ends_at'   => '2026-10-10 11:00:00',
            'capacity'  => 5,
            'status'    => 'active',
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

    // ------------------------------------------------------------------
    // Owner / Admin : les trois sections
    // ------------------------------------------------------------------

    public function testOwnerSeesAllThreeSections(): void
    {
        $result = $this->getDashboard($this->ownerId);

        $result->assertStatus(200);
        $result->assertSee(self::MARKER_PARTICIPANTS);
        $result->assertSee(self::MARKER_MY_SIGNUPS);
        // Données de stands présentes (section Modification et/ou Participants).
        $result->assertSee(self::MARKER_STAND_DATA);
        $result->assertSee(self::MARKER_TEAM);

        // Tous les outils de la section « Modification » sont présents : cela
        // donne du sens aux assertions d'absence pour les autres rôles.
        foreach (self::MODIFICATION_TOOLS as $tool) {
            $result->assertSee($tool);
        }
    }

    public function testAdminSeesAllThreeSections(): void
    {
        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertSee(self::MARKER_PARTICIPANTS);
        $result->assertSee(self::MARKER_MY_SIGNUPS);
        $result->assertSee(self::MARKER_STAND_DATA);
        $result->assertSee(self::MARKER_TEAM);

        foreach (self::MODIFICATION_TOOLS as $tool) {
            $result->assertSee($tool);
        }
    }

    // ------------------------------------------------------------------
    // Gestionnaire : participants + mes participations, PAS modification
    // ------------------------------------------------------------------

    public function testGestionnaireSeesParticipantsAndMySignupsButNotModification(): void
    {
        $result = $this->getDashboard($this->gestionId);

        $result->assertStatus(200);
        $result->assertSee(self::MARKER_PARTICIPANTS);
        $result->assertSee(self::MARKER_MY_SIGNUPS);
        // Story 4.4 : le Gestionnaire voit désormais les stands/créneaux DANS la section
        // « Gestion des participants » (surface autorisée), donc le nom du stand apparaît.
        $result->assertSee(self::MARKER_STAND_DATA);

        // UX-DR16 : TOUS les outils d'édition de la section Modification restent ABSENTS
        // du HTML (pas seulement désactivés) pour le Gestionnaire.
        foreach (self::MODIFICATION_TOOLS as $tool) {
            $result->assertDontSee($tool);
        }
        $result->assertDontSee(self::MARKER_TEAM);
    }

    // ------------------------------------------------------------------
    // Bénévole : uniquement "Mes participations"
    // ------------------------------------------------------------------

    public function testBenevoleSeesOnlyMySignups(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertSee(self::MARKER_MY_SIGNUPS);

        // Ni Modification ni Gestion des participants ne doivent apparaître, et aucune
        // donnée de stand ne doit fuiter au Bénévole (minimisation maintenue — NFR5).
        $result->assertDontSee(self::MARKER_PARTICIPANTS);
        $result->assertDontSee(self::MARKER_TEAM);
        $result->assertDontSee(self::MARKER_STAND_DATA);
        foreach (self::MODIFICATION_TOOLS as $tool) {
            $result->assertDontSee($tool);
        }
    }

    // ------------------------------------------------------------------
    // Utilisateur sans rôle sur la kermesse : 403 (RoleFilter — NFR4)
    // ------------------------------------------------------------------

    public function testUserWithoutRoleIsForbidden(): void
    {
        $result = $this->getDashboard($this->strangerId);

        $result->assertStatus(403);
        $this->assertStringContainsString('unauthorized_role', (string) $result->response()->getBody());
    }
}
