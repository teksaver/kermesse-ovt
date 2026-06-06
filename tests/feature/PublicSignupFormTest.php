<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the public signup form GET/POST /k/{slug}/slots/{id}/signup (Story 3.2).
 *
 * Focus: slot link on volunteer page, form display, server-side validation, 404 boundaries,
 * and the hard privacy boundary (no volunteer/owner/admin data exposed).
 *
 * @internal
 */
final class PublicSignupFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_owners (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                email_hash TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'owner_pending\',
                email_verified_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_kermesses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
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
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id INTEGER NOT NULL,
                volunteer_name TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'active\',
                deleted_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
        $db->query('DELETE FROM db_owners');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertKermesse(string $slug, string $status = 'open'): int
    {
        $db    = db_connect();
        $email = "owner-{$slug}@secret-owner.example";
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Secret Owner Name', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, '{$slug}', 'Kermesse de test', '2026-09-12', 'Cour centrale', 'Venez nombreux', 'Europe/Paris', '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        return (int) $db->insertID();
    }

    private function insertStand(int $kermesseId, string $name = 'Buvette', string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_stands (kermesse_id, name, display_order, status, created_at, updated_at)
            VALUES ({$kermesseId}, '" . addslashes($name) . "', 0, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSlot(int $standId, int $capacity = 5, string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_slots (stand_id, starts_at, ends_at, capacity, status, created_at, updated_at)
            VALUES ({$standId}, '2026-09-12 09:00:00', '2026-09-12 10:30:00', {$capacity}, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function csrfPost(string $url, array $data): mixed
    {
        // The shared Security instance generates its hash lazily in the constructor.
        // We read that hash and pass it as the POST token so verify() passes.
        $security             = service('security');
        $data[$security->getTokenName()] = $security->getHash();
        return $this->post($url, $data);
    }

    // ------------------------------------------------------------------
    // AC1 — Available slot on volunteer page has a real signup link
    // ------------------------------------------------------------------

    public function testAvailableSlotOnVolunteerPageHasSignupLink(): void
    {
        $kermesseId = $this->insertKermesse('ecole-link');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 5);

        $result = $this->get('k/ecole-link');
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertMatchesRegularExpression(
            '#href="[^"]+/k/ecole-link/slots/' . $slotId . '/signup"#',
            $body,
            'An available slot must link to its signup form URL',
        );
    }

    public function testFullSlotOnVolunteerPageHasNoSignupLink(): void
    {
        $kermesseId = $this->insertKermesse('ecole-full-link');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 1);

        $db = db_connect();
        $db->query("INSERT INTO db_signups (slot_id, volunteer_name, status, deleted_at, created_at, updated_at)
            VALUES ({$slotId}, 'Bénévole', 'active', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $result = $this->get('k/ecole-full-link');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('slot-row--full', $body);
        $this->assertStringContainsString('aria-disabled="true"', $body);
        $this->assertStringNotContainsString('/signup', $body);
    }

    // ------------------------------------------------------------------
    // AC1 + AC2 — GET signup form
    // ------------------------------------------------------------------

    public function testGetSignupFormShowsSlotSummaryAndFields(): void
    {
        $kermesseId = $this->insertKermesse('ecole-form');
        $standId    = $this->insertStand($kermesseId, 'Crêperie');
        $slotId     = $this->insertSlot($standId, 8);

        $result = $this->get("k/ecole-form/slots/{$slotId}/signup");
        $result->assertOK();
        $body = $result->response()->getBody();

        // Summary
        $this->assertStringContainsString('Kermesse de test', $body);
        $this->assertStringContainsString('Crêperie', $body);
        $this->assertStringContainsString('09:00 - 10:30', $body);
        $this->assertStringContainsString('8 sur 8', $body);

        // Fields with visible labels
        $this->assertStringContainsString('for="first_name"', $body);
        $this->assertStringContainsString('for="last_name"', $body);
        $this->assertStringContainsString('for="email"', $body);
        $this->assertStringContainsString('for="phone"', $body);

        // Placeholders are present but labels must also exist (labels are the primary identifiers)
        $this->assertStringContainsString('Prénom', $body);
        $this->assertStringContainsString('Nom', $body);
        $this->assertStringContainsString('Email', $body);
        $this->assertStringContainsString('Téléphone', $body);
        $this->assertStringContainsString('facultatif', $body);
    }

    public function testGetSignupFormHasMobileViewportMeta(): void
    {
        $kermesseId = $this->insertKermesse('ecole-mobile-form');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-mobile-form/slots/{$slotId}/signup");
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('width=device-width', $body);
        $this->assertStringContainsString('initial-scale=1', $body);
    }

    // ------------------------------------------------------------------
    // AC3 — POST validation: preserve values, inline errors, summary
    // ------------------------------------------------------------------

    public function testPostInvalidEmailShowsFieldError(): void
    {
        $kermesseId = $this->insertKermesse('ecole-post-err');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-post-err/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'not-an-email',
            'phone'      => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('field-error', $body);
        // Submitted value preserved
        $this->assertStringContainsString('not-an-email', $body);
        $this->assertStringContainsString('Marie', $body);
    }

    public function testPostMissingRequiredFieldsPreservesValuesAndShowsErrors(): void
    {
        $kermesseId = $this->insertKermesse('ecole-post-miss');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-post-miss/slots/{$slotId}/signup", [
            'first_name' => '',
            'last_name'  => '',
            'email'      => 'valid@email.example',
            'phone'      => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('field-error', $body);
        // Values preserved
        $this->assertStringContainsString('valid@email.example', $body);
    }

    public function testPostMultipleInvalidFieldsShowsErrorSummary(): void
    {
        $kermesseId = $this->insertKermesse('ecole-summary');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-summary/slots/{$slotId}/signup", [
            'first_name' => '',
            'last_name'  => '',
            'email'      => '',
            'phone'      => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        // Global summary rendered when ≥2 fields fail
        $this->assertStringContainsString('form-error-summary', $body);
        $this->assertStringContainsString('Veuillez corriger', $body);
    }

    public function testPostPhoneOptionalDoesNotBlockValidSubmit(): void
    {
        $kermesseId = $this->insertKermesse('ecole-phone-opt');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-phone-opt/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@exemple.fr',
            'phone'      => '',
        ]);

        // Valid submit redirects to volunteer page (no inscription created yet in 3.2)
        $this->assertSame(302, $result->response()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // 404 boundaries — neutral responses for mismatches
    // ------------------------------------------------------------------

    public function testUnknownSlugReturnNeutral404OnGetForm(): void
    {
        $result = $this->get('k/does-not-exist/slots/999/signup');
        $this->assertSame(404, $result->response()->getStatusCode());
        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
    }

    public function testUnknownSlotIdReturnsNeutral404(): void
    {
        $kermesseId = $this->insertKermesse('ecole-404-slot');
        $this->insertStand($kermesseId);

        $result = $this->get('k/ecole-404-slot/slots/99999/signup');
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testInactiveSlotReturnsNeutral404(): void
    {
        $kermesseId = $this->insertKermesse('ecole-inactive-slot');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 5, 'inactive');

        $result = $this->get("k/ecole-inactive-slot/slots/{$slotId}/signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testSlotFromDifferentKermesseReturnsNeutral404(): void
    {
        $kermesseA = $this->insertKermesse('ecole-a');
        $kermesseB = $this->insertKermesse('ecole-b');
        $standB    = $this->insertStand($kermesseB);
        $slotB     = $this->insertSlot($standB);

        // Accessing slot from kermesse B via kermesse A's slug must 404
        $result = $this->get("k/ecole-a/slots/{$slotB}/signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testNonOpenKermesseReturnsNeutral404OnFormUrl(): void
    {
        $kermesseId = $this->insertKermesse('ecole-prep-form', 'preparation');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-prep-form/slots/{$slotId}/signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testInactiveStandReturnsNeutral404OnFormUrl(): void
    {
        $kermesseId = $this->insertKermesse('ecole-inactive-stand');
        $standId    = $this->insertStand($kermesseId, 'Stand archivé', 'deactivated');
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-inactive-stand/slots/{$slotId}/signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Privacy — form must not expose volunteer/owner/admin data
    // ------------------------------------------------------------------

    public function testSignupFormDoesNotLeakInternalData(): void
    {
        $kermesseId = $this->insertKermesse('ecole-priv-form');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-priv-form/slots/{$slotId}/signup");
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('Secret Owner Name', $body);
        $this->assertStringNotContainsString('secret-owner.example', $body);
        $this->assertStringNotContainsString('/admin/', $body);
        $this->assertStringNotContainsString('owner_id', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('management', $body);
    }
}
