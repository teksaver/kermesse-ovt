<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SignupModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 4.3 — Annuler une inscription (se désister).
 *
 * AC1 : depuis « Mes participations », un bénévole annule son inscription active ;
 *       la place redevient immédiatement disponible et le message
 *       « La place est de nouveau disponible. » s'affiche (UX-DR23).
 * AC2 : kermesse fermée → l'action est indisponible et rejetée côté serveur
 *       (« Les inscriptions sont fermées. », signups_not_open).
 * Sécurité : un bénévole ne peut annuler que sa propre inscription (ownership).
 *
 * @internal
 */
final class CancelSignupTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId        = 0;
    private int $benevoleId     = 0;
    private int $autreBenevoleId = 0;
    private int $kermesseId     = 0;
    private int $slotId         = 0;
    private int $signupId       = 0;

    private const STAND = 'Stand Buvette 4.3';

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
    // AC1 — Bouton visible quand ouvert / caché quand fermé
    // ------------------------------------------------------------------

    public function testBenevoleSeesCancelButtonWhenOpen(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertSee('Annuler ma participation');
    }

    public function testCancelButtonHiddenWhenKermesseClosed(): void
    {
        $this->setKermesseStatus('closed');

        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        // L'inscription reste listée, mais l'action d'annulation est indisponible (AC2).
        $result->assertSee(self::STAND);
        $result->assertDontSee('Annuler ma participation');
    }

    // ------------------------------------------------------------------
    // AC1 — Annulation : place libérée + message
    // ------------------------------------------------------------------

    public function testBenevoleCancelsOwnSignupFreesSlotAndShowsMessage(): void
    {
        // Pré-condition : la place est occupée.
        $this->assertSame(1, $this->activeCount());

        $result = $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/signups/{$this->signupId}/cancel");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}"));

        // L'inscription est annulée et la place est immédiatement libérée.
        $this->assertSame(SignupModel::STATUS_CANCELLED, $this->signupStatus());
        $this->assertSame(0, $this->activeCount());

        // Message de confirmation (UX-DR23).
        $this->assertSame(
            'La place est de nouveau disponible.',
            (string) session()->getFlashdata('participation_notice'),
        );
    }

    public function testMessageIsRenderedOnDashboardAfterCancel(): void
    {
        $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/signups/{$this->signupId}/cancel");

        // La participation annulée disparaît de la liste active.
        $result = $this->getDashboard($this->benevoleId);
        $result->assertStatus(200);
        $result->assertSee('aucune inscription active');
    }

    // ------------------------------------------------------------------
    // AC2 — Kermesse fermée : annulation rejetée côté serveur
    // ------------------------------------------------------------------

    public function testCancelRejectedWhenKermesseClosed(): void
    {
        $this->setKermesseStatus('closed');

        $result = $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/signups/{$this->signupId}/cancel");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}"));

        // L'inscription reste active : aucune mutation quand les inscriptions sont fermées.
        $this->assertSame(SignupModel::STATUS_ACTIVE, $this->signupStatus());
        $this->assertSame(1, $this->activeCount());

        $this->assertSame(
            'Les inscriptions sont fermées.',
            (string) session()->getFlashdata('participation_error'),
        );
    }

    // ------------------------------------------------------------------
    // Sécurité — un bénévole ne peut annuler que sa propre inscription
    // ------------------------------------------------------------------

    public function testBenevoleCannotCancelAnotherVolunteersSignup(): void
    {
        $result = $this->withSession($this->session($this->autreBenevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/signups/{$this->signupId}/cancel");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}"));

        // L'inscription de l'autre bénévole n'est pas touchée.
        $this->assertSame(SignupModel::STATUS_ACTIVE, $this->signupStatus());
        $this->assertSame(1, $this->activeCount());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@cancel.test', 'Owner', 'Test'],
            ['benevole@cancel.test', 'Benevole', 'Test'],
            ['autre@cancel.test', 'Autre', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '', 
            ]);
        }

        $rows                  = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->ownerId         = (int) $rows[0]['id'];
        $this->benevoleId      = (int) $rows[1]['id'];
        $this->autreBenevoleId = (int) $rows[2]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->ownerId,
            'public_slug' => 'cancel-43',
            'name'        => 'Kermesse Annulation 4.3',
            'location'    => 'Salle de test',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insertBatch([
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->ownerId,         'role' => 'owner'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->benevoleId,      'role' => 'benevole'],
            ['kermesse_id' => $this->kermesseId, 'user_id' => $this->autreBenevoleId, 'role' => 'benevole'],
        ]);

        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => self::STAND,
            'display_order' => 1,
            'status'        => 'active',
        ]);
        $standId = (int) $db->insertID();

        // Capacité 1 : une seule annulation doit suffire à rouvrir la place.
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-10 09:00:00',
            'ends_at'   => '2026-10-10 12:00:00',
            'capacity'  => 1,
            'status'    => 'active',
        ]);
        $this->slotId = (int) $db->insertID();

        $db->table('signups')->insert([
            'slot_id'    => $this->slotId,
            'user_id'    => $this->benevoleId,
            'status'     => 'active',
            'deleted_at' => null,
        ]);
        $this->signupId = (int) $db->insertID();
    }

    private function setKermesseStatus(string $status): void
    {
        db_connect()->table('kermesses')->where('id', $this->kermesseId)->update(['status' => $status]);
    }

    private function activeCount(): int
    {
        return model(SignupModel::class)->countActiveBySlotIds([$this->slotId])[$this->slotId] ?? 0;
    }

    private function signupStatus(): string
    {
        $row = db_connect()->table('signups')->select('status')->where('id', $this->signupId)->get()->getRowArray();

        return (string) ($row['status'] ?? '');
    }

    private function session(int $userId): array
    {
        return ['user_id' => $userId, 'is_logged_in' => true];
    }

    private function getDashboard(int $userId): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession($this->session($userId))->get("kermesse/{$this->kermesseId}");
    }

    private function csrfPost(string $url, array $data = []): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
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
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                status     TEXT    NOT NULL DEFAULT "active",
                deleted_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
