<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the public volunteer page GET /k/{public_slug} (Story 3.1).
 *
 * Focus: per-status rendering (preparation/open/closed), full-slot handling, and
 * the hard privacy boundary — no user/admin data, no management links.
 *
 * @internal
 */
final class PublicVolunteerPageTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                email_hash TEXT NOT NULL UNIQUE,
                first_name TEXT NOT NULL DEFAULT \'\',
                last_name TEXT NOT NULL DEFAULT \'\',
                phone TEXT NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_kermesses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_by INTEGER NOT NULL,
                public_slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                event_date TEXT NOT NULL,
                location TEXT NOT NULL,
                short_description TEXT,
                timezone TEXT NOT NULL DEFAULT \'Europe/Paris\',
                status TEXT NOT NULL DEFAULT \'preparation\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_stands (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stand_id INTEGER NOT NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                capacity INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
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

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertKermesse(string $slug, string $status): int
    {
        $db    = db_connect();
        $email = "owner-{$slug}@secret-owner.example";
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Secret', 'Owner', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (created_by, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, '{$slug}', 'Kermesse de printemps', '2026-09-12', 'Cour centrale', 'Venez nombreux', 'Europe/Paris', '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        return (int) $db->insertID();
    }

    private function insertStand(int $kermesseId, string $name, int $order = 0, string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_stands (kermesse_id, name, display_order, status, created_at, updated_at)
            VALUES ({$kermesseId}, '" . addslashes($name) . "', {$order}, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSlot(int $standId, int $capacity = 5, string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_slots (stand_id, starts_at, ends_at, capacity, status, created_at, updated_at)
            VALUES ({$standId}, '2026-09-12 09:00:00', '2026-09-12 10:30:00', {$capacity}, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, string $name = '', string $state = 'active', ?string $deletedAt = null): int
    {
        $db   = db_connect();
        $cols = 'slot_id, user_id, created_at, updated_at';
        $vals = "{$slotId}, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP";

        if ($state === 'cancelled') {
            $cols .= ', canceled_at, canceled_by';
            $vals .= ", CURRENT_TIMESTAMP, 0";
        } elseif ($state === 'refused') {
            $cols .= ', rejected_at';
            $vals .= ', CURRENT_TIMESTAMP';
        } elseif ($deletedAt !== null || in_array($state, ['deactivated', 'deleted'], true)) {
            $ts    = $deletedAt ?? date('Y-m-d H:i:s');
            $cols .= ', deleted_at';
            $vals .= ", '" . addslashes($ts) . "'";
        }

        $db->query("INSERT INTO db_signups ({$cols}, first_name, last_name) VALUES ({$vals}, '" . addslashes($name) . "', '')");
        return (int) $db->insertID();
    }

    // ------------------------------------------------------------------
    // AC1 — preparation
    // ------------------------------------------------------------------

    public function testPreparationShowsBaseInfoAndNotOpenMessageWithoutPlanning(): void
    {
        $kermesseId = $this->insertKermesse('ecole-prep', 'preparation');
        $standId    = $this->insertStand($kermesseId, 'Barbe à papa');
        $this->insertSlot($standId, 5);

        $result = $this->get('k/ecole-prep');
        $result->assertOK();
        $body = $result->response()->getBody();

        // Base public info present
        $this->assertStringContainsString('Kermesse de printemps', $body);
        $this->assertStringContainsString('Cour centrale', $body);
        // Clear "not open yet" message
        $this->assertStringContainsString('ne sont pas encore ouvertes', $body);
        // No planning leaked: no stand name, no slot time, no remaining counter
        $this->assertStringNotContainsString('Barbe à papa', $body);
        $this->assertStringNotContainsString('09:00', $body);
        $this->assertStringNotContainsString('restante', $body);
        // Login affordance still shown for anonymous visitor
        $this->assertMatchesRegularExpression('#href="[^"]*auth/login[^"]*"#', $body);
    }

    // ------------------------------------------------------------------
    // AC2 — open
    // ------------------------------------------------------------------

    public function testOpenShowsStandsFirstWithSlotTimeRemainingAndCapacity(): void
    {
        $kermesseId = $this->insertKermesse('ecole-open', 'open');
        $standId    = $this->insertStand($kermesseId, 'Pêche aux canards');
        $this->insertSlot($standId, 6);

        $result = $this->get('k/ecole-open');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Pêche aux canards', $body);
        $this->assertStringContainsString('09:00 - 10:30', $body);
        // Remaining (= capacity when no signups) and total capacity shown
        $this->assertStringContainsString('6 places restantes', $body);
        $this->assertStringContainsString('sur 6', $body);
        // Available slot rendered as a real <a> link to the signup form
        $this->assertStringContainsString('slot-row--available', $body);
        $this->assertMatchesRegularExpression('#href="[^"]+/slots/\d+/signup"#', $body);
        $this->assertStringContainsString('Déjà inscrit', $body);
        $this->assertMatchesRegularExpression('#href="[^"]*auth/login[^"]*"#', $body);
    }

    public function testOpenRemainingReflectsActiveSignups(): void
    {
        $kermesseId = $this->insertKermesse('ecole-open2', 'open');
        $standId    = $this->insertStand($kermesseId, 'Crêpes');
        $slotId     = $this->insertSlot($standId, 5);
        $this->insertSignup($slotId, 'Jean Dupont', 'active');
        $this->insertSignup($slotId, 'Marie Martin', 'cancelled'); // must not count

        $result = $this->get('k/ecole-open2');
        $body   = $result->response()->getBody();

        // 5 capacity - 1 active = 4 remaining
        $this->assertStringContainsString('4 places restantes', $body);
        $this->assertStringContainsString('sur 5', $body);
    }

    public function testOpenDoesNotExposeInactiveStandsOrInactiveSlots(): void
    {
        $kermesseId      = $this->insertKermesse('ecole-active-only', 'open');
        $activeStandId   = $this->insertStand($kermesseId, 'Jeux actifs', 1);
        $inactiveStandId = $this->insertStand($kermesseId, 'Stand archivé secret', 2, 'deactivated');
        $this->insertSlot($activeStandId, 5, 'inactive');
        $this->insertSlot($inactiveStandId, 5);

        $result = $this->get('k/ecole-active-only');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Aucun créneau disponible pour le moment', $body);
        $this->assertStringNotContainsString('Choisissez un créneau', $body);
        $this->assertStringNotContainsString('Stand archivé secret', $body);
        $this->assertStringNotContainsString('09:00 - 10:30', $body);
        $this->assertStringNotContainsString('slot-row--available', $body);
    }

    public function testOpenRemainingIgnoresSoftDeletedSignups(): void
    {
        $kermesseId = $this->insertKermesse('ecole-soft-delete', 'open');
        $standId    = $this->insertStand($kermesseId, 'Accueil');
        $slotId     = $this->insertSlot($standId, 3);
        $this->insertSignup($slotId, 'Bénévole Actif', 'active');
        $this->insertSignup($slotId, 'Bénévole Supprimé', 'active', '2026-09-01 12:00:00');

        $result = $this->get('k/ecole-soft-delete');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('2 places restantes', $body);
        $this->assertStringContainsString('sur 3', $body);
        $this->assertStringNotContainsString('Bénévole Actif', $body);
        $this->assertStringNotContainsString('Bénévole Supprimé', $body);
    }

    // ------------------------------------------------------------------
    // AC3 — closed
    // ------------------------------------------------------------------

    public function testClosedShowsClosedMessageWithoutPlanningOrSignup(): void
    {
        $kermesseId = $this->insertKermesse('ecole-closed', 'closed');
        $standId    = $this->insertStand($kermesseId, 'Tombola');
        $this->insertSlot($standId, 5);

        $result = $this->get('k/ecole-closed');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('clôturées', $body);
        $this->assertStringNotContainsString('Tombola', $body);
        $this->assertStringNotContainsString('slot-row--available', $body);
        $this->assertStringNotContainsString('role="button"', $body);
        // Login affordance still shown for anonymous visitor
        $this->assertMatchesRegularExpression('#href="[^"]*auth/login[^"]*"#', $body);
    }

    // ------------------------------------------------------------------
    // AC4 — full slot stays visible but disabled
    // ------------------------------------------------------------------

    public function testFullSlotStaysVisibleAndDisabledWithCompletLabel(): void
    {
        $kermesseId = $this->insertKermesse('ecole-full', 'open');
        $standId    = $this->insertStand($kermesseId, 'Maquillage');
        $slotId     = $this->insertSlot($standId, 2);
        $this->insertSignup($slotId, 'Bénévole Un', 'active');
        $this->insertSignup($slotId, 'Bénévole Deux', 'active');

        $result = $this->get('k/ecole-full');
        $body   = $result->response()->getBody();

        // Slot still shown (time visible) but flagged full + disabled
        $this->assertStringContainsString('09:00 - 10:30', $body);
        $this->assertStringContainsString('Complet', $body);
        $this->assertStringContainsString('slot-row--full', $body);
        $this->assertStringContainsString('aria-disabled="true"', $body);
        // A full slot is not a tappable available row
        $this->assertStringNotContainsString('slot-row--available', $body);
    }

    // ------------------------------------------------------------------
    // AC5 — privacy: no user/admin data, no management link
    // ------------------------------------------------------------------

    public function testPublicPageDoesNotLeakUserOrAdminData(): void
    {
        $kermesseId = $this->insertKermesse('ecole-privacy', 'open');
        $standId    = $this->insertStand($kermesseId, 'Buvette');
        $slotId     = $this->insertSlot($standId, 4);
        $this->insertSignup($slotId, 'Jean Dupont', 'active');

        $result = $this->get('k/ecole-privacy');
        $body   = $result->response()->getBody();

        // No volunteer identity
        $this->assertStringNotContainsString('Jean Dupont', $body);
        // No owner identity / email
        $this->assertStringNotContainsString('Secret Owner', $body);
        $this->assertStringNotContainsString('secret-owner.example', $body);
        // No admin or management surfaces
        $this->assertStringNotContainsString('/admin/', $body);
        $this->assertStringNotContainsString('owner_id', $body);
        $this->assertStringNotContainsString('management', $body);
        // No leaked internals
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
    }

    // ------------------------------------------------------------------
    // AC6 — mobile-first scaffolding present
    // ------------------------------------------------------------------

    public function testPublicPageHasResponsiveViewportMeta(): void
    {
        $this->insertKermesse('ecole-mobile', 'preparation');

        $result = $this->get('k/ecole-mobile');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('width=device-width', $body);
        $this->assertStringContainsString('initial-scale=1', $body);
    }

    // ------------------------------------------------------------------
    // Unknown slug — neutral 404
    // ------------------------------------------------------------------

    public function testUnknownSlugReturnsNeutral404(): void
    {
        $result = $this->get('k/does-not-exist');

        $this->assertSame(404, $result->response()->getStatusCode());
        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
    }

    // ------------------------------------------------------------------
    // Login affordance — visibility rules
    // ------------------------------------------------------------------

    public function testLoginAffordanceHiddenWhenAlreadyAuthenticated(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-hidden', 'open');
        $standId    = $this->insertStand($kermesseId, 'Maquillage');
        $this->insertSlot($standId, 5);

        $result = $this->withSession(['is_logged_in' => true])->get('k/ecole-auth-hidden');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('Déjà inscrit', $body);
        $this->assertStringContainsString('Maquillage', $body);
    }

    public function testOpenWithNoSlotsShowsLoginAffordanceForAnonymousUser(): void
    {
        $this->insertKermesse('ecole-no-slots', 'open');

        $result = $this->get('k/ecole-no-slots');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Aucun créneau disponible', $body);
        $this->assertStringContainsString('Déjà inscrit', $body);
        $this->assertMatchesRegularExpression('#href="[^"]*auth/login[^"]*"#', $body);
    }
}
