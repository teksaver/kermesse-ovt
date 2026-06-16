<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 4.4 — Afficher la « Gestion des participants » (Admin/Gestionnaire).
 *
 * Couvre l'AC1 : pour un rôle autorisé (Owner/Admin/Gestionnaire), chaque stand et
 * chaque créneau s'affiche avec ses places occupées/restantes ET la liste nominative
 * des bénévoles inscrits (nom, prénom, téléphone). Ces données personnelles :
 *   - n'apparaissent QUE pour un rôle autorisé (jamais pour un Bénévole — NFR5) ;
 *   - utilisent la MÊME définition d'inscription active que le planning public
 *     (une inscription annulée n'est ni comptée ni nommée — UX-DR23).
 *
 * @internal
 */
final class ManageParticipantsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId      = 0;
    private int $adminId      = 0;
    private int $gestionId    = 0;
    private int $benevoleId   = 0;
    private int $camilleId    = 0;
    private int $hugoId       = 0;
    private int $annuleId     = 0;
    private int $kermesseId   = 0;
    private int $buvetteSlot  = 0;

    private const STAND_NAME = 'Buvette 4.4';

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
    // AC1 — Rôle autorisé : stand, créneau, comptage et liste nominative
    // ------------------------------------------------------------------

    public function testAdminSeesParticipantsWithIdentityAndContact(): void
    {
        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertSee('Gestion des inscrits');
        $result->assertSee(self::STAND_NAME);

        // Identité et contact des bénévoles actifs (UX-DR24).
        $result->assertSee('Durand');
        $result->assertSee('Camille');
        $result->assertSee('0611223344');
        $result->assertSee('camille.durand@participant.test');
        $result->assertSee('Bernard');
        $result->assertSee('Hugo');
        $result->assertSee('0655667788');
    }

    public function testGestionnaireSeesParticipants(): void
    {
        $result = $this->getDashboard($this->gestionId);

        $result->assertStatus(200);
        $result->assertSee('Gestion des inscrits');
        $result->assertSee(self::STAND_NAME);
        $result->assertSee('Durand');
        $result->assertSee('0611223344');
    }

    public function testOccupiedAndRemainingCountsMatchActiveSignups(): void
    {
        // Capacité 3, deux inscriptions actives, une annulée → 2 occupées, 1 restante.
        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertSee('Occupé : 2 / 3');
        $result->assertSee('Restant : 1');
    }

    public function testCancelledSignupVolunteerIsExcluded(): void
    {
        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        // Le bénévole dont l'inscription est annulée n'est ni nommé ni compté (UX-DR23).
        // (Nom volontairement distinct de toute copie d'UI comme « Annuler ».)
        $result->assertDontSee('Lefebvre');
        $result->assertDontSee('quentin.lefebvre@participant.test');
    }

    public function testManagerOnKermesseWithoutStandsSeesEmptyState(): void
    {
        // Kermesse sans aucun stand : la section s'affiche avec un état vide,
        // sans erreur (chemin « pas de stand » — évite la lecture participants).
        $db = db_connect();
        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'manage-participants-44-empty',
            'name'        => 'Kermesse Sans Stand 4.4',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $emptyKermesseId = (int) $db->insertID();
        $db->table('kermesse_user_roles')->insert(
            ['kermesse_id' => $emptyKermesseId, 'user_id' => $this->adminId, 'role' => 'admin']
        );

        $result = $this->withSession($this->session($this->adminId))->get("kermesse/{$emptyKermesseId}");

        $result->assertStatus(200);
        $result->assertSee('Gestion des inscrits');
        $result->assertSee("Aucun stand n'a encore été créé");
    }

    // ------------------------------------------------------------------
    // Story 5.3 — Badge de modification dans « Gestion des inscrits »
    // ------------------------------------------------------------------

    public function testAdminSeesModificationBadgeWhenSignupWasModified(): void
    {
        $db = db_connect();
        $db->table('signups')
            ->where('slot_id', $this->buvetteSlot)
            ->where('user_id', $this->camilleId)
            ->update([
                'last_modified_by_user_id' => $this->adminId,
                'last_modified_at'         => '2026-10-10 15:00:00',
            ]);

        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertSee('Modifié par Admin');
        $result->assertSee('le 10/10/2026 à 15:00');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('role="note"', $body);
        $this->assertStringContainsString('aria-label="Modifié par Admin le 10/10/2026 à 15:00"', $body);
    }

    public function testModificationBadgeFallsBackWhenModifierFirstNameIsEmpty(): void
    {
        $db = db_connect();
        $db->table('users')
            ->where('id', $this->adminId)
            ->update(['first_name' => '']);
        $db->table('signups')
            ->where('slot_id', $this->buvetteSlot)
            ->where('user_id', $this->camilleId)
            ->update([
                'last_modified_by_user_id' => $this->adminId,
                'last_modified_at'         => '2026-10-10 15:00:00',
            ]);

        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertSee('Modifié par un administrateur');
        $result->assertSee('le 10/10/2026 à 15:00');
    }

    public function testNoBadgeWhenSignupWasNotModified(): void
    {
        $result = $this->getDashboard($this->adminId);

        $result->assertStatus(200);
        $result->assertDontSee('Modifié par');
    }

    // ------------------------------------------------------------------
    // NFR5 — Confidentialité : un Bénévole ne voit ni la section ni aucune PII
    // ------------------------------------------------------------------

    public function testBenevoleSeesNeitherSectionNorOtherVolunteersPII(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertDontSee('Gestion des inscrits');

        // Aucune donnée personnelle d'un autre bénévole ne doit fuiter.
        $result->assertDontSee('Durand');
        $result->assertDontSee('Bernard');
        $result->assertDontSee('0611223344');
        $result->assertDontSee('camille.durand@participant.test');
        // Données de stand non chargées hors section autorisée (minimisation).
        $result->assertDontSee(self::STAND_NAME);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@manage-4-4.test', 'Owner', 'Test', ''],
            ['admin@manage-4-4.test', 'Admin', 'Test', ''],
            ['gestion@manage-4-4.test', 'Gestion', 'Test', ''],
            ['benevole@manage-4-4.test', 'Benevole', 'Test', ''],
            ['camille.durand@participant.test', 'Camille', 'Durand', '0611223344'],
            ['hugo.bernard@participant.test', 'Hugo', 'Bernard', '0655667788'],
            ['quentin.lefebvre@participant.test', 'Quentin', 'Lefebvre', '0600000000'],
        ] as [$email, $first, $last, $phone]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => $phone, 
            ]);
        }

        $rows             = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId    = (int) $rows[0]['id'];
        $this->adminId    = (int) $rows[1]['id'];
        $this->gestionId  = (int) $rows[2]['id'];
        $this->benevoleId = (int) $rows[3]['id'];
        $this->camilleId  = (int) $rows[4]['id'];
        $this->hugoId     = (int) $rows[5]['id'];
        $this->annuleId   = (int) $rows[6]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'manage-participants-44',
            'name'        => 'Kermesse Participants 4.4',
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

        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => self::STAND_NAME,
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();

        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-10 09:00:00',
            'ends_at'   => '2026-10-10 12:00:00',
            'capacity'  => 3,
            'status'    => 'active',
        ]);
        $this->buvetteSlot = (int) $db->insertID();

        // Deux inscriptions actives + une annulée sur le même créneau.
        $this->insertSignup($this->buvetteSlot, $this->camilleId, 'active');
        $this->insertSignup($this->buvetteSlot, $this->hugoId, 'active');
        $this->insertSignup($this->buvetteSlot, $this->annuleId, 'cancelled');
    }

    private function insertSignup(int $slotId, int $userId, string $status): void
    {
        db_connect()->table('signups')->insert([
            'slot_id'    => $slotId,
            'user_id'    => $userId,
            'status'     => $status,
            'deleted_at' => null,
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
                last_modified_by_user_id  INTEGER  NULL DEFAULT NULL,
                last_modified_at          DATETIME NULL DEFAULT NULL,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
