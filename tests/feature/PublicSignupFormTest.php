<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the public signup form GET/POST /k/{slug}/slots/{id}/signup (Stories 3.2 & 3.3).
 *
 * Focus: slot link on volunteer page, form display, server-side validation, 404 boundaries,
 * the hard privacy boundary (no volunteer/owner/admin data exposed), volunteer create/reuse,
 * email normalization, and signup confirmation page.
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
            CREATE TABLE IF NOT EXISTS db_volunteers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id INTEGER NOT NULL,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_signups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id INTEGER NOT NULL,
                volunteer_id INTEGER NOT NULL DEFAULT 0,
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
        $db->query('DELETE FROM db_volunteers');
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
        $db->query("INSERT INTO db_volunteers (kermesse_id, first_name, last_name, email, phone, created_at, updated_at)
            VALUES ({$kermesseId}, 'Test', 'Bénévole', 'benevole@test.example', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $volunteerIdFull = (int) $db->insertID();
        $db->query("INSERT INTO db_signups (slot_id, volunteer_id, status, deleted_at, created_at, updated_at)
            VALUES ({$slotId}, {$volunteerIdFull}, 'active', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

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

        // Valid submit redirects to confirmation page (signup created in 3.3)
        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString('/signup/confirmation', $result->response()->getHeaderLine('Location'));
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

    // ------------------------------------------------------------------
    // Story 3.3 — AC1: valid POST creates volunteer + signup, redirects
    // ------------------------------------------------------------------

    public function testValidSubmitCreatesVolunteerRowInDb(): void
    {
        $kermesseId = $this->insertKermesse('ecole-create-vol');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-create-vol/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@exemple.fr',
            'phone'      => '',
        ]);

        $db  = db_connect();
        $row = $db->query("SELECT * FROM db_volunteers WHERE email = 'marie@exemple.fr'")->getRowArray();

        $this->assertNotNull($row, 'A volunteer row must be created after successful signup');
        $this->assertSame('Marie', $row['first_name']);
        $this->assertSame('Dupont', $row['last_name']);
    }

    public function testValidSubmitCreatesSignupRow(): void
    {
        $kermesseId = $this->insertKermesse('ecole-create-signup');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-create-signup/slots/{$slotId}/signup", [
            'first_name' => 'Jean',
            'last_name'  => 'Martin',
            'email'      => 'jean@martin.fr',
            'phone'      => '',
        ]);

        $db    = db_connect();
        $count = (int) $db->query("SELECT COUNT(*) AS cnt FROM db_signups WHERE slot_id = {$slotId}")->getRowArray()['cnt'];

        $this->assertSame(1, $count, 'One signup row must exist after successful POST');
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC2: existing email reuses volunteer row
    // ------------------------------------------------------------------

    public function testExistingEmailReusesVolunteerRow(): void
    {
        $kermesseId = $this->insertKermesse('ecole-reuse-vol');
        $standId    = $this->insertStand($kermesseId);
        $slotIdA    = $this->insertSlot($standId, 5);
        $slotIdB    = $this->insertSlot($standId, 5);

        $db = db_connect();
        $db->query("INSERT INTO db_volunteers (kermesse_id, first_name, last_name, email, phone, created_at, updated_at)
            VALUES ({$kermesseId}, 'Marie', 'Dupont', 'reuse@exemple.fr', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $this->csrfPost("k/ecole-reuse-vol/slots/{$slotIdB}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'reuse@exemple.fr',
            'phone'      => '',
        ]);

        $count = (int) $db->query("SELECT COUNT(*) AS cnt FROM db_volunteers WHERE email = 'reuse@exemple.fr'")->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'Only one volunteer row must exist; duplicates are not created');
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC3: email casing treated as same identity
    // ------------------------------------------------------------------

    public function testUpperCaseEmailNormalizedToLowercase(): void
    {
        $kermesseId = $this->insertKermesse('ecole-case-norm');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-case-norm/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'Marie@EXEMPLE.FR',
            'phone'      => '',
        ]);

        $db  = db_connect();
        $row = $db->query("SELECT email FROM db_volunteers WHERE kermesse_id = {$kermesseId}")->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('marie@exemple.fr', $row['email'], 'Stored email must be normalized to lowercase');
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC4: confirmation page shown after successful signup
    // ------------------------------------------------------------------

    public function testSuccessfulSubmitRedirectsToConfirmationPage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-confirm/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'confirm@exemple.fr',
            'phone'      => '',
        ]);

        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString(
            "k/ecole-confirm/slots/{$slotId}/signup/confirmation",
            $result->response()->getHeaderLine('Location'),
        );
    }

    public function testConfirmationPageShowsSuccessMessage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm-page');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        // CI4 FeatureTestTrait: flash data set during POST is still in 'new' state and not
        // yet promoted for the subsequent GET. Inject it directly via withSession() using
        // the CI4 internal flash format so confirm() finds it immediately.
        $flashSession = ['signup_success' => true, '__ci_vars' => ['signup_success' => 'new']];

        $this->csrfPost("k/ecole-confirm-page/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'confirm-page@exemple.fr',
            'phone'      => '',
        ]);

        $result = $this->withSession($flashSession)
            ->get("k/ecole-confirm-page/slots/{$slotId}/signup/confirmation");
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('confirmée', $body);
        $this->assertStringContainsString('email de confirmation', $body);
    }

    public function testConfirmationPageDoesNotExposeInternalIds(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm-priv');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $flashSession = ['signup_success' => true, '__ci_vars' => ['signup_success' => 'new']];

        $this->csrfPost("k/ecole-confirm-priv/slots/{$slotId}/signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'priv@exemple.fr',
            'phone'      => '',
        ]);

        $result = $this->withSession($flashSession)
            ->get("k/ecole-confirm-priv/slots/{$slotId}/signup/confirmation");
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('volunteer_id', $body);
        $this->assertStringNotContainsString('/admin/', $body);
        $this->assertStringNotContainsString('management', $body);
    }
}
