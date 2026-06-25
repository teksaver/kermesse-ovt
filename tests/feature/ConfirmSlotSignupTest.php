<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SlotSignupModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 5.14 — Flux de confirmation d'identité (AC2/AC3/AC4).
 *
 * AC2 : dans « Mes participations », une inscription non confirmée (accepted_at IS NULL,
 *       created_by != user_id ou NULL) affiche les boutons « Accepter » et « Refuser ».
 *       Une inscription auto-créée (certified) ne les affiche pas.
 * AC3 : POST /accept → accepted_at renseigné (statut calculé = certified), flash notice.
 * AC4 : POST /reject → rejected_at renseigné (statut calculé = refused), place libérée,
 *       flash notice.
 * Sécurité : un bénévole ne peut accepter/refuser que sa propre inscription.
 *
 * @internal
 */
final class ConfirmSlotSignupTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private int $ownerId         = 0;
    private int $benevoleId      = 0;
    private int $autreBenevoleId = 0;
    private int $kermesseId      = 0;
    private int $slotId          = 0;

    /** Signup created by an admin (created_by = ownerId ≠ benevoleId) → unconfirmed */
    private int $unconfirmedSignupId = 0;

    /** Signup created by the volunteer themselves (created_by = benevoleId) → certified */
    private int $certifiedSignupId = 0;

    private int $slot2Id            = 0;

    private const STAND = 'Stand Buvette 5.14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_slot_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC2 — Badges de confirmation dans « Mes participations »
    // ------------------------------------------------------------------

    public function testUnconfirmedSignupShowsConfirmationButtons(): void
    {
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertSee('En attente de confirmation');
        $result->assertSee('Accepter');
        $result->assertSee('Refuser');
    }

    public function testSelfCreatedSignupDoesNotShowConfirmationButtons(): void
    {
        // The certified signup (created_by = benevoleId) must NOT show the confirmation UI.
        // The cancel button should appear instead (kermesse is open).
        $result = $this->getDashboard($this->benevoleId);

        $result->assertStatus(200);
        $result->assertSee('Annuler ma participation');
        // Only ONE badge must appear (for the unconfirmed signup). The aria-label attribute
        // is unique per badge, so counting it gives the exact badge count.
        $this->assertSame(
            1,
            substr_count((string) $result->getBody(), 'aria-label="En attente de confirmation"'),
        );
    }

    // ------------------------------------------------------------------
    // AC3 — Accepter une inscription non confirmée
    // ------------------------------------------------------------------

    public function testBenevoleAcceptsUnconfirmedSignupSetsAcceptedAt(): void
    {
        $result = $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/slot-signups/{$this->unconfirmedSignupId}/accept");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}") . '#participations');

        $this->assertSame('certified', $this->signupStatus($this->unconfirmedSignupId));
        $this->assertSame(
            'Votre participation a été confirmée.',
            (string) session()->getFlashdata('participation_notice'),
        );
    }

    public function testAcceptedSignupCountsTowardCapacity(): void
    {
        // Before accept: unconfirmed is still counted (orphan policy).
        $before = $this->activeCount($this->slotId);

        $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/slot-signups/{$this->unconfirmedSignupId}/accept");

        // After accept: still counted (certified is active).
        $this->assertSame($before, $this->activeCount($this->slotId));
    }

    // ------------------------------------------------------------------
    // AC4 — Refuser une inscription non confirmée
    // ------------------------------------------------------------------

    public function testBenevoleRejectsUnconfirmedSignupFreesSlot(): void
    {
        $before = $this->activeCount($this->slotId);

        $result = $this->withSession($this->session($this->benevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/slot-signups/{$this->unconfirmedSignupId}/reject");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}") . '#participations');

        $this->assertSame('refused', $this->signupStatus($this->unconfirmedSignupId));
        $this->assertSame($before - 1, $this->activeCount($this->slotId));
        $this->assertSame(
            'Votre refus a été enregistré. La place a été libérée.',
            (string) session()->getFlashdata('participation_notice'),
        );
    }

    // ------------------------------------------------------------------
    // Sécurité — un bénévole ne peut pas toucher la participation d'autrui
    // ------------------------------------------------------------------

    public function testAnotherBenevoleCannotAcceptSomeoneElsesSignup(): void
    {
        $result = $this->withSession($this->session($this->autreBenevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/slot-signups/{$this->unconfirmedSignupId}/accept");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}") . '#participations');

        // Signup unchanged.
        $this->assertSame('unconfirmed', $this->signupStatus($this->unconfirmedSignupId));
        $this->assertNotEmpty((string) session()->getFlashdata('participation_error'));
    }

    public function testAnotherBenevoleCannotRejectSomeoneElsesSignup(): void
    {
        $result = $this->withSession($this->session($this->autreBenevoleId))
            ->csrfPost("kermesse/{$this->kermesseId}/slot-signups/{$this->unconfirmedSignupId}/reject");

        $result->assertRedirectTo(site_url("kermesse/{$this->kermesseId}") . '#participations');

        $this->assertSame('unconfirmed', $this->signupStatus($this->unconfirmedSignupId));
        $this->assertNotEmpty((string) session()->getFlashdata('participation_error'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['owner@confirm514.test', 'Owner', 'Test'],
            ['benevole@confirm514.test', 'Benevole', 'Test'],
            ['autre@confirm514.test', 'Autre', 'Test'],
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
            'public_slug' => 'confirm-514',
            'name'        => 'Kermesse Confirmation 5.14',
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

        // Slot 1 : inscription non confirmée (admin a créé pour le bénévole).
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-10 09:00:00',
            'ends_at'   => '2026-10-10 12:00:00',
            'capacity'  => 2,
            'status'    => 'active',
        ]);
        $this->slotId = (int) $db->insertID();

        // Signup créé par l'admin (created_by = ownerId ≠ benevoleId) → statut unconfirmed.
        $db->table('slot_signups')->insert([
            'slot_id'    => $this->slotId,
            'user_id'    => $this->benevoleId,
            'created_by' => $this->ownerId,
        ]);
        $this->unconfirmedSignupId = (int) $db->insertID();

        // Slot 2 : inscription auto-créée (certified) — contrôle pour AC2.
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-10-10 14:00:00',
            'ends_at'   => '2026-10-10 16:00:00',
            'capacity'  => 2,
            'status'    => 'active',
        ]);
        $this->slot2Id = (int) $db->insertID();

        // Signup créé par le bénévole lui-même → accepted_at renseigné d'office (certified).
        $db->table('slot_signups')->insert([
            'slot_id'    => $this->slot2Id,
            'user_id'    => $this->benevoleId,
            'created_by' => $this->benevoleId,
            'accepted_at' => date('Y-m-d H:i:s'),
        ]);
        $this->certifiedSignupId = (int) $db->insertID();
    }

    private function signupStatus(int $signupId): string
    {
        $row = db_connect()->table('slot_signups')->where('id', $signupId)->get()->getRowArray();

        return $row !== null ? SlotSignupModel::getStatus($row) : '';
    }

    private function activeCount(int $slotId): int
    {
        return model(SlotSignupModel::class)->countActiveBySlotIds([$slotId])[$slotId] ?? 0;
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
                user_id INTEGER NULL,
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
                status        TEXT    NOT NULL DEFAULT \'active\',
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
                status     TEXT     NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slot_signups (
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
