<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the public signup form GET/POST /k/{slug}/slots/{id}/signup (Stories 3.2 & 3.3).
 *
 * Focus: slot link on volunteer page, form display, server-side validation, 404 boundaries,
 * the hard privacy boundary (no user/admin data exposed), user create/reuse,
 * email normalization, and signup confirmation page.
 *
 * @internal
 */
final class PublicSlotSignupFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_profile_divergences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                kermesse_id INTEGER NOT NULL,
                signup_id INTEGER,
                submitted_first_name TEXT NOT NULL DEFAULT \'\',
                submitted_last_name TEXT NOT NULL DEFAULT \'\',
                submitted_phone TEXT NOT NULL DEFAULT \'\',
                resolved_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
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
        $db->query('
            CREATE TABLE IF NOT EXISTS db_email_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'sent\',
                recipient_email TEXT NOT NULL,
                recipient_hash TEXT NOT NULL,
                error_message TEXT,
                metadata TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                token_type TEXT NOT NULL,
                user_id INTEGER,
                owner_id INTEGER,
                kermesse_id INTEGER,
                email TEXT,
                expires_at DATETIME NOT NULL,
                used_at DATETIME,
                revoked_at DATETIME,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_slot_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        $db->query('DELETE FROM db_email_events');
        $db->query('DELETE FROM db_profile_divergences');
        $db->query('DELETE FROM db_access_tokens');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertUser(string $email, string $firstName, string $lastName): int
    {
        return (new \App\Models\UserModel())->findOrCreateWithProfile($email, $firstName, $lastName);
    }

    private function insertKermesse(string $slug, string $status = 'open'): int
    {
        $db    = db_connect();
        $email = "owner-{$slug}@secret-owner.example";
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Secret', 'Owner', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (created_by, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
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
            '#href="[^"]+/k/ecole-link/slots/' . $slotId . '/slot-signup"#',
            $body,
            'An available slot must link to its signup form URL',
        );
    }

    public function testFullSlotOnVolunteerPageHasNoSignupLink(): void
    {
        $kermesseId = $this->insertKermesse('ecole-full-link');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 1);

        $db    = db_connect();
        $userIdFull = $this->insertUser('benevole@test.example', 'Test', 'Bénévole');
        $db->query("INSERT INTO db_slot_signups (slot_id, user_id, created_at, updated_at)
            VALUES ({$slotId}, {$userIdFull}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $result = $this->get('k/ecole-full-link');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('slot-row--full', $body);
        $this->assertStringContainsString('aria-disabled="true"', $body);
        $this->assertStringNotContainsString('/slot-signup', $body);
    }

    // ------------------------------------------------------------------
    // AC1 + AC2 — GET signup form
    // ------------------------------------------------------------------

    public function testGetSignupFormShowsSlotSummaryAndFields(): void
    {
        $kermesseId = $this->insertKermesse('ecole-form');
        $standId    = $this->insertStand($kermesseId, 'Crêperie');
        $slotId     = $this->insertSlot($standId, 8);

        $result = $this->get("k/ecole-form/slots/{$slotId}/slot-signup");
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

        $result = $this->get("k/ecole-mobile-form/slots/{$slotId}/slot-signup");
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

        $result = $this->csrfPost("k/ecole-post-err/slots/{$slotId}/slot-signup", [
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

        $result = $this->csrfPost("k/ecole-post-miss/slots/{$slotId}/slot-signup", [
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

        $result = $this->csrfPost("k/ecole-summary/slots/{$slotId}/slot-signup", [
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

        $result = $this->csrfPost("k/ecole-phone-opt/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@exemple.fr',
            'phone'      => '',
        ]);

        // Valid submit redirects to confirmation page (signup created in 3.3)
        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString('/slot-signup/confirmation', $result->response()->getHeaderLine('Location'));
    }

    // ------------------------------------------------------------------
    // 404 boundaries — neutral responses for mismatches
    // ------------------------------------------------------------------

    public function testUnknownSlugReturnNeutral404OnGetForm(): void
    {
        $result = $this->get('k/does-not-exist/slots/999/slot-signup');
        $this->assertSame(404, $result->response()->getStatusCode());
        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
    }

    public function testUnknownSlotIdReturnsNeutral404(): void
    {
        $kermesseId = $this->insertKermesse('ecole-404-slot');
        $this->insertStand($kermesseId);

        $result = $this->get('k/ecole-404-slot/slots/99999/slot-signup');
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testInactiveSlotReturnsNeutral404(): void
    {
        $kermesseId = $this->insertKermesse('ecole-inactive-slot');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 5, 'inactive');

        $result = $this->get("k/ecole-inactive-slot/slots/{$slotId}/slot-signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testSlotFromDifferentKermesseReturnsNeutral404(): void
    {
        $kermesseA = $this->insertKermesse('ecole-a');
        $kermesseB = $this->insertKermesse('ecole-b');
        $standB    = $this->insertStand($kermesseB);
        $slotB     = $this->insertSlot($standB);

        // Accessing slot from kermesse B via kermesse A's slug must 404
        $result = $this->get("k/ecole-a/slots/{$slotB}/slot-signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testNonOpenKermesseReturnsNeutral404OnFormUrl(): void
    {
        $kermesseId = $this->insertKermesse('ecole-prep-form', 'preparation');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-prep-form/slots/{$slotId}/slot-signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    public function testInactiveStandReturnsNeutral404OnFormUrl(): void
    {
        $kermesseId = $this->insertKermesse('ecole-inactive-stand');
        $standId    = $this->insertStand($kermesseId, 'Stand archivé', 'deactivated');
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-inactive-stand/slots/{$slotId}/slot-signup");
        $this->assertSame(404, $result->response()->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Privacy — form must not expose user/admin data
    // ------------------------------------------------------------------

    public function testSignupFormDoesNotLeakInternalData(): void
    {
        $kermesseId = $this->insertKermesse('ecole-priv-form');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-priv-form/slots/{$slotId}/slot-signup");
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('secret-owner.example', $body);
        $this->assertStringNotContainsString('/admin/', $body);
        $this->assertStringNotContainsString('owner_id', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('management', $body);
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC1: valid POST creates user + signup, redirects
    // ------------------------------------------------------------------

    public function testValidSubmitDoesNotCreateUserRowInDb(): void
    {
        $kermesseId = $this->insertKermesse('ecole-create-user');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-create-user/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@exemple.fr',
            'phone'      => '',
        ]);

        $db  = db_connect();
        $row = $db->query("SELECT * FROM db_users WHERE email = 'marie@exemple.fr'")->getRowArray();

        $this->assertNull($row, 'No user row should be created after successful signup (Story 5.14)');
    }

    public function testValidSubmitCreatesSignupRow(): void
    {
        $kermesseId = $this->insertKermesse('ecole-create-signup');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-create-signup/slots/{$slotId}/slot-signup", [
            'first_name' => 'Jean',
            'last_name'  => 'Martin',
            'email'      => 'jean@martin.fr',
            'phone'      => '',
        ]);

        $db    = db_connect();
        $count = (int) $db->query("SELECT COUNT(*) AS cnt FROM db_slot_signups WHERE slot_id = {$slotId}")->getRowArray()['cnt'];

        $this->assertSame(1, $count, 'One signup row must exist after successful POST');
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC2: existing email reuses user row
    // ------------------------------------------------------------------

    public function testExistingEmailReusesUserRow(): void
    {
        $kermesseId = $this->insertKermesse('ecole-reuse-user');
        $standId    = $this->insertStand($kermesseId);
        $slotIdA    = $this->insertSlot($standId, 5);
        $slotIdB    = $this->insertSlot($standId, 5);

        $db = db_connect(); $this->insertUser('reuse@exemple.fr', 'Marie', 'Dupont');

        $this->csrfPost("k/ecole-reuse-user/slots/{$slotIdB}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'reuse@exemple.fr',
            'phone'      => '',
        ]);

        $count = (int) $db->query("SELECT COUNT(*) AS cnt FROM db_users WHERE email = 'reuse@exemple.fr'")->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'Only one user row must exist; duplicates are not created');
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC3: email casing treated as same identity
    // ------------------------------------------------------------------

    public function testUpperCaseEmailNormalizedToLowercase(): void
    {
        $kermesseId = $this->insertKermesse('ecole-case-norm');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-case-norm/slots/{$slotId}/slot-signup", [
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => ' JOHN.Doe@Example.COM  ',
            'phone'      => '',
        ]);

        $signup = db_connect()->query(
            "SELECT email FROM db_slot_signups WHERE email = 'john.doe@example.com'"
        )->getRowArray();

        $this->assertNotNull($signup);
        $this->assertSame('john.doe@example.com', $signup['email']);
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC4: confirmation page shown after successful signup
    // ------------------------------------------------------------------

    public function testSuccessfulSubmitRedirectsToConfirmationPage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-confirm/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'confirm@exemple.fr',
            'phone'      => '',
        ]);

        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString(
            "k/ecole-confirm/slots/{$slotId}/slot-signup/confirmation",
            $result->response()->getHeaderLine('Location'),
        );
    }

    /**
     * Build the scoped confirmation flash exactly as submit() stores it. CI4
     * FeatureTestTrait boots a fresh request per call, so the POST's flash is not
     * promoted for a subsequent GET; we inject it via withSession() using the CI4
     * internal flash format. testSuccessfulSubmitStoresScopedConfirmationFlash
     * asserts that submit() really writes this exact payload shape.
     */
    private function confirmationFlash(
        string $slug,
        int $slotId,
        string $kermesseName = 'Kermesse de test',
        ?bool $emailSent = true,
    ): array {
        return [
            'signup_success' => [
                'slug'         => $slug,
                'slotId'       => $slotId,
                'kermesseName' => $kermesseName,
                'emailSent'    => $emailSent,
            ],
            '__ci_vars'      => ['signup_success' => 'new'],
        ];
    }

    public function testSuccessfulSubmitStoresScopedConfirmationFlash(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm-flash');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-confirm-flash/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'flash@exemple.fr',
            'phone'      => '',
        ]);

        // The flash must be scoped to the slot signed up, never a bare boolean:
        // confirm() refuses to render any other slug/slot from it.
        $flash = session()->getFlashdata('signup_success');
        $this->assertIsArray($flash);
        $this->assertSame('ecole-confirm-flash', $flash['slug'] ?? null);
        $this->assertSame($slotId, $flash['slotId'] ?? null);
        $this->assertSame('Kermesse de test', $flash['kermesseName'] ?? null);
    }

    public function testConfirmationPageShowsSuccessMessage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm-page');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->withSession($this->confirmationFlash('ecole-confirm-page', $slotId))
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

        $result = $this->withSession($this->confirmationFlash('ecole-confirm-priv', $slotId))
            ->get("k/ecole-confirm-priv/slots/{$slotId}/signup/confirmation");
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('volunteer_id', $body);
        $this->assertStringNotContainsString('user_id', $body);
        $this->assertStringNotContainsString('/admin/', $body);
        $this->assertStringNotContainsString('management', $body);
    }

    public function testConfirmationPageWithoutFlashRedirectsToKermesse(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm-noflash');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-confirm-noflash/slots/{$slotId}/signup/confirmation");

        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString('k/ecole-confirm-noflash', $result->response()->getHeaderLine('Location'));
    }

    public function testConfirmationPageWithMismatchedSlotRedirects(): void
    {
        $kermesseId  = $this->insertKermesse('ecole-confirm-scope');
        $standId     = $this->insertStand($kermesseId);
        $slotId      = $this->insertSlot($standId);
        $otherSlotId = $this->insertSlot($standId);

        // Flash earned on $slotId must not unlock the confirmation of $otherSlotId
        $result = $this->withSession($this->confirmationFlash('ecole-confirm-scope', $slotId))
            ->get("k/ecole-confirm-scope/slots/{$otherSlotId}/signup/confirmation");

        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString('k/ecole-confirm-scope', $result->response()->getHeaderLine('Location'));
    }

    public function testConfirmationPageSurvivesKermesseClosingAfterSignup(): void
    {
        $kermesseId = $this->insertKermesse('ecole-confirm-closed');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        // Kermesse closes between the POST and the confirmation GET: the signup is
        // already recorded, the volunteer must still see their confirmation.
        db_connect()->query("UPDATE db_kermesses SET status = 'closed' WHERE id = {$kermesseId}");

        $result = $this->withSession($this->confirmationFlash('ecole-confirm-closed', $slotId))
            ->get("k/ecole-confirm-closed/slots/{$slotId}/signup/confirmation");

        $result->assertOK();
        $this->assertStringContainsString('confirmée', $result->response()->getBody());
    }

    // ------------------------------------------------------------------
    // Story 3.5 — confirmation email per signup, traced in email_events
    // ------------------------------------------------------------------

    public function testValidSubmitRecordsSignupConfirmationEmailEvent(): void
    {
        $kermesseId = $this->insertKermesse('ecole-email-event');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-email-event/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'Marie@Event.FR',
            'phone'      => '',
        ]);

        // AC3: success or failure, the attempt must be traced with the normalized recipient
        $row = db_connect()->query(
            "SELECT recipient_email, status FROM db_email_events WHERE event_type = 'signup_confirmation'"
        )->getRowArray();

        $this->assertNotNull($row, 'A signup_confirmation email_event must be recorded');
        $this->assertSame('marie@event.fr', $row['recipient_email']);
        $this->assertContains($row['status'], ['sent', 'failed']);
    }

    public function testEachSignupSendsDistinctConfirmationEmail(): void
    {
        $kermesseId = $this->insertKermesse('ecole-email-multi');
        $standId    = $this->insertStand($kermesseId);
        $slotA      = $this->insertSlotWithTimes($standId, '2026-09-12 09:00:00', '2026-09-12 10:30:00');
        $slotB      = $this->insertSlotWithTimes($standId, '2026-09-12 11:00:00', '2026-09-12 12:30:00');

        $fields = ['first_name' => 'Marie', 'last_name' => 'Dupont', 'email' => 'multi@event.fr', 'phone' => ''];
        $this->csrfPost("k/ecole-email-multi/slots/{$slotA}/signup", $fields);
        $this->csrfPost("k/ecole-email-multi/slots/{$slotB}/slot-signup", $fields);

        // AC2: one distinct confirmation email attempt per accepted signup
        $count = (int) db_connect()->query(
            "SELECT COUNT(*) AS cnt FROM db_email_events WHERE event_type = 'signup_confirmation'"
        )->getRow()->cnt;

        $this->assertSame(2, $count);
    }

    public function testRefusedSignupRecordsNoEmailEvent(): void
    {
        $kermesseId = $this->insertKermesse('ecole-email-refused', 'closed');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-email-refused/slots/{$slotId}/slot-signup", [
            'first_name' => 'Bob',
            'last_name'  => 'Dupont',
            'email'      => 'refused@event.fr',
            'phone'      => '',
        ]);

        $count = (int) db_connect()->query(
            "SELECT COUNT(*) AS cnt FROM db_email_events WHERE event_type = 'signup_confirmation'"
        )->getRow()->cnt;

        $this->assertSame(0, $count, 'A refused signup must not trigger any confirmation email');
    }

    public function testConfirmationPageShowsEmailFailureNotice(): void
    {
        $kermesseId = $this->insertKermesse('ecole-email-fail');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->withSession($this->confirmationFlash('ecole-email-fail', $slotId, emailSent: false))
            ->get("k/ecole-email-fail/slots/{$slotId}/signup/confirmation");

        $result->assertOK();
        $body = $result->response()->getBody();

        // AC4: the signup stays confirmed and the page says what to do
        $this->assertStringContainsString('confirmée', $body);
        $this->assertStringContainsString('a pas pu être envoyé', $body);
        $this->assertStringContainsString('organisateur', $body);
    }

    public function testValidSubmitCreatesMagicLinkTokenForVolunteer(): void
    {
        $kermesseId = $this->insertKermesse('ecole-token-create');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-token-create/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@token.fr',
            'phone'      => '',
        ]);

        // AC1: a magic_link token must be created for the volunteer's email
        $row = db_connect()->query(
            "SELECT token_type, email, kermesse_id FROM db_access_tokens WHERE token_type = 'magic_link' AND email = 'marie@token.fr'"
        )->getRowArray();

        $this->assertNotNull($row, 'A magic_link token must be created in access_tokens after a successful signup');
        $this->assertSame('marie@token.fr', $row['email']);
        $this->assertSame((string) $kermesseId, (string) $row['kermesse_id']);
    }

    public function testConfirmationPageDoesNotMentionManagementLink(): void
    {
        $kermesseId = $this->insertKermesse('ecole-email-nolink');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->withSession($this->confirmationFlash('ecole-email-nolink', $slotId))
            ->get("k/ecole-email-nolink/slots/{$slotId}/signup/confirmation");

        // Écart acté 2026-06-10 : aucun lien de gestion promis tant que le modèle
        // d'identité (lien de gestion vs Magic Link) n'est pas tranché.
        $this->assertStringNotContainsString('lien de gestion', $result->response()->getBody());
    }

    // ------------------------------------------------------------------
    // Story 3.2 — AC2: Session pre-fill after successful signup
    // ------------------------------------------------------------------

    public function testSuccessfulSubmitSavesIdentityToSession(): void
    {
        $kermesseId = $this->insertKermesse('ecole-session-save');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $this->csrfPost("k/ecole-session-save/slots/{$slotId}/slot-signup", [
            'first_name' => 'Hélène',
            'last_name'  => 'Bernard',
            'email'      => 'helene@session.fr',
            'phone'      => '',
        ]);

        $identity = session()->get('volunteer_identity');
        $this->assertIsArray($identity, 'Session must store volunteer_identity after successful signup');
        $this->assertSame('Hélène',            $identity['first_name'] ?? null);
        $this->assertSame('Bernard',            $identity['last_name']  ?? null);
        $this->assertSame('helene@session.fr',  $identity['email']      ?? null);
    }

    public function testGetFormPreFillsFromSessionIdentity(): void
    {
        $kermesseId = $this->insertKermesse('ecole-prefill');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        // Simulate a volunteer who already signed up once in this session
        $sessionData = [
            'volunteer_identity' => [
                'first_name' => 'Hélène',
                'last_name'  => 'Bernard',
                'email'      => 'helene@prefill.fr',
            ],
        ];

        $result = $this->withSession($sessionData)
            ->get("k/ecole-prefill/slots/{$slotId}/slot-signup");

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Hélène',           $body, 'First name must be pre-filled from session');
        $this->assertStringContainsString('Bernard',           $body, 'Last name must be pre-filled from session');
        $this->assertStringContainsString('helene@prefill.fr', $body, 'Email must be pre-filled from session');
    }

    public function testGetFormWithoutSessionShowsEmptyFields(): void
    {
        $kermesseId = $this->insertKermesse('ecole-empty-fields');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-empty-fields/slots/{$slotId}/slot-signup");
        $result->assertOK();
        $body = $result->response()->getBody();

        // No stale data injected
        $this->assertStringNotContainsString('value="Hélène"', $body);
        $this->assertStringNotContainsString('secret-owner.example', $body);
    }

    public function testSessionPreFillDoesNotLeakPhoneToForm(): void
    {
        $kermesseId = $this->insertKermesse('ecole-nophone');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        // Even if phone is in session (shouldn't be), the form must not pre-fill it
        $sessionData = [
            'volunteer_identity' => [
                'first_name' => 'Hélène',
                'last_name'  => 'Bernard',
                'email'      => 'helene@nophone.fr',
                'phone'      => '0601020304',
            ],
        ];

        $result = $this->withSession($sessionData)
            ->get("k/ecole-nophone/slots/{$slotId}/slot-signup");

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('0601020304', $body, 'Phone must not be pre-filled from session (privacy)');
    }

    // ------------------------------------------------------------------
    // Review 3.4 — Privacy: "Ce n'est pas vous ?" clears the session identity
    // so a prefilled name/email cannot leak to the next visitor on a shared device.
    // ------------------------------------------------------------------

    public function testPrefilledFormShowsForgetAffordance(): void
    {
        $kermesseId = $this->insertKermesse('ecole-forget-shown');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->withSession([
            'volunteer_identity' => [
                'first_name' => 'Hélène',
                'last_name'  => 'Bernard',
                'email'      => 'helene@forget.fr',
            ],
        ])->get("k/ecole-forget-shown/slots/{$slotId}/slot-signup");

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Ce n\'est pas vous', $body, 'Le formulaire pré-rempli doit proposer d\'effacer l\'identité');
        $this->assertStringContainsString('class="signup-forget"', $body, 'Le formulaire doit poster vers l\'action forget');
    }

    public function testEmptyFormDoesNotShowForgetAffordance(): void
    {
        $kermesseId = $this->insertKermesse('ecole-forget-hidden');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->get("k/ecole-forget-hidden/slots/{$slotId}/slot-signup");

        $result->assertOK();
        $this->assertStringNotContainsString('Ce n\'est pas vous', $result->response()->getBody(), 'Sans pré-remplissage, pas d\'affordance d\'effacement');
    }

    public function testForgetClearsSessionIdentityAndRedirects(): void
    {
        $kermesseId = $this->insertKermesse('ecole-forget-post');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->withSession([
            'volunteer_identity' => [
                'first_name' => 'Hélène',
                'last_name'  => 'Bernard',
                'email'      => 'helene@forget.fr',
            ],
        ])->csrfPost("k/ecole-forget-post/slots/{$slotId}/signup/forget", []);

        $result->assertRedirectTo(site_url("k/ecole-forget-post/slots/{$slotId}/slot-signup"));
        $this->assertNull(session()->get('volunteer_identity'), 'L\'identité de session doit être effacée');
    }


    // ------------------------------------------------------------------
    // Story 3.3 — AC1: Connected user GET shows locked profile + confirm
    // ------------------------------------------------------------------

    public function testConnectedUserGetShowsProfileData(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-get');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $userId = $this->insertUser('marie@connected.fr', 'Marie', 'Dupont');

        $result = $this->withSession(['is_logged_in' => true, 'user_id' => $userId])
            ->get("k/ecole-auth-get/slots/{$slotId}/slot-signup");

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Marie', $body);
        $this->assertStringContainsString('Dupont', $body);
        $this->assertStringContainsString('marie@connected.fr', $body);
    }

    public function testConnectedUserGetShowsConfirmButtonWithoutEditableFields(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-btn');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $userId = $this->insertUser('btn@connected.fr', 'Sophie', 'Martin');

        $result = $this->withSession(['is_logged_in' => true, 'user_id' => $userId])
            ->get("k/ecole-auth-btn/slots/{$slotId}/slot-signup");

        $body = $result->response()->getBody();

        $this->assertStringContainsString('Confirmer', $body, 'Bouton de confirmation requis pour utilisateur connecté');
        $this->assertStringNotContainsString('name="first_name"', $body, 'Pas de champ éditable first_name pour utilisateur connecté');
        $this->assertStringNotContainsString('name="email"', $body, 'Pas de champ éditable email pour utilisateur connecté');
    }

    public function testConnectedUserDbDataTakesPrecedenceOverVolunteerIdentitySession(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-precedence');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $userId = $this->insertUser('db@priority.fr', 'DbPrenom', 'DbNom');

        $sessionData = [
            'is_logged_in'       => true,
            'user_id'            => $userId,
            'volunteer_identity' => [
                'first_name' => 'StalePrenom',
                'last_name'  => 'StaleNom',
                'email'      => 'stale@old.fr',
            ],
        ];

        $result = $this->withSession($sessionData)
            ->get("k/ecole-auth-precedence/slots/{$slotId}/slot-signup");

        $body = $result->response()->getBody();

        $this->assertStringContainsString('DbPrenom', $body, 'DB first_name doit être affiché');
        $this->assertStringContainsString('DbNom', $body, 'DB last_name doit être affiché');
        $this->assertStringNotContainsString('StalePrenom', $body, 'volunteer_identity ne doit pas prendre le dessus');
        $this->assertStringNotContainsString('stale@old.fr', $body, 'Email volunteer_identity obsolète ne doit pas apparaître');
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC2: Connected user POST uses DB data, bypasses validation
    // ------------------------------------------------------------------

    public function testConnectedUserSubmitCreatesSignupUsingDbData(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-submit');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $userId = $this->insertUser('auth@signup.fr', 'Auth', 'User');

        $security = service('security');
        $result   = $this->withSession(['is_logged_in' => true, 'user_id' => $userId])
            ->post("k/ecole-auth-submit/slots/{$slotId}/slot-signup", [
                $security->getTokenName() => $security->getHash(),
            ]);

        $this->assertSame(302, $result->response()->getStatusCode());
        $this->assertStringContainsString('/slot-signup/confirmation', $result->response()->getHeaderLine('Location'));

        $count = (int) db_connect()->query(
            "SELECT COUNT(*) AS cnt FROM db_slot_signups WHERE slot_id = {$slotId} AND user_id = {$userId} AND canceled_at IS NULL AND rejected_at IS NULL AND deleted_at IS NULL"
        )->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'L\'inscription doit être rattachée à l\'utilisateur connecté');
    }

    public function testConnectedUserSubmitIgnoresForgedPostFields(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-forge');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $userId = $this->insertUser('real@user.fr', 'Real', 'User');

        $security = service('security');
        $result   = $this->withSession(['is_logged_in' => true, 'user_id' => $userId])
            ->post("k/ecole-auth-forge/slots/{$slotId}/slot-signup", [
                $security->getTokenName() => $security->getHash(),
                'first_name' => 'Attaquant',
                'last_name'  => 'Falsifié',
                'email'      => 'forge@attaquant.fr',
                'phone'      => '',
            ]);

        $this->assertSame(302, $result->response()->getStatusCode(), 'L\'inscription doit réussir avec les données DB, pas POST');

        $fakeUser = db_connect()->query(
            "SELECT * FROM db_users WHERE email = 'forge@attaquant.fr'"
        )->getRowArray();
        $this->assertNull($fakeUser, 'L\'email forgé ne doit pas créer de ligne utilisateur');

        $count = (int) db_connect()->query(
            "SELECT COUNT(*) AS cnt FROM db_slot_signups WHERE slot_id = {$slotId} AND user_id = {$userId}"
        )->getRowArray()['cnt'];
        $this->assertSame(1, $count, 'L\'inscription doit être rattachée à l\'utilisateur réel, pas à l\'identité forgée');
    }

    public function testConnectedUserSubmitDoesNotCreateProfileDivergence(): void
    {
        $kermesseId = $this->insertKermesse('ecole-auth-nodiverg');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $userId = $this->insertUser('nodiv@connected.fr', 'NoDiverg', 'User');

        $security = service('security');
        $this->withSession(['is_logged_in' => true, 'user_id' => $userId])
            ->post("k/ecole-auth-nodiverg/slots/{$slotId}/slot-signup", [
                $security->getTokenName() => $security->getHash(),
            ]);

        $count = (int) db_connect()->query(
            'SELECT COUNT(*) AS cnt FROM db_profile_divergences'
        )->getRowArray()['cnt'];
        $this->assertSame(0, $count, 'Aucune divergence de profil ne doit être enregistrée pour un utilisateur connecté');
    }

    // ------------------------------------------------------------------
    // Story 3.4 helpers
    // ------------------------------------------------------------------

    private function insertSlotWithTimes(
        int    $standId,
        string $startsAt,
        string $endsAt,
        int    $capacity = 5,
    ): int {
        $db = db_connect();
        $db->query("INSERT INTO db_slots (stand_id, starts_at, ends_at, capacity, status, created_at, updated_at)
            VALUES ({$standId}, '{$startsAt}', '{$endsAt}', {$capacity}, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC1: Slot capacity enforcement
    //
    // The POST always reaches SlotSignupService, which re-counts active signups
    // under the slot FOR UPDATE lock and returns slot_full when the capacity
    // is reached; the controller re-renders the form with the exact AC1
    // message. (The GET form still redirects early when the summary says the
    // slot is full — stale-form POSTs are the case exercised here.)
    // ------------------------------------------------------------------

    public function testPostToFullSlotShowsFullMessage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-slot-full');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 1); // capacity = 1

        // Pre-fill the single spot with an existing active signup
        $db    = db_connect();
        $email = 'alice@full.fr';
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Alice', 'Martin', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $aliceId = (int) $db->insertID();
        $db->query("INSERT INTO db_slot_signups (slot_id, user_id, created_at, updated_at)
            VALUES ({$slotId}, {$aliceId}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $result = $this->csrfPost("k/ecole-slot-full/slots/{$slotId}/slot-signup", [
            'first_name' => 'Bob',
            'last_name'  => 'Dupont',
            'email'      => 'bob@new.fr',
            'phone'      => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        // Exact AC1 message, in its HTML-escaped form
        $this->assertStringContainsString('Ce créneau vient d&#039;être rempli. Choisissez un autre créneau.', $body);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC4: Closed kermesse shows service message on POST
    // ------------------------------------------------------------------

    public function testPostToClosedKermesseRedirectsToPublicPage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-closed-post', 'closed');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId);

        $result = $this->csrfPost("k/ecole-closed-post/slots/{$slotId}/slot-signup", [
            'first_name' => 'Bob',
            'last_name'  => 'Dupont',
            'email'      => 'bob@closed.fr',
            'phone'      => '',
        ]);

        $result->assertRedirectTo(site_url('k/ecole-closed-post'));
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC2: Duplicate signup detection (service-level message)
    // ------------------------------------------------------------------

    public function testPostWithDuplicateSignupShowsDuplicateMessage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-dup-signup');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 5);

        // Pre-register Marie on this slot
        $db    = db_connect();
        $email = 'marie@dup.fr';
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Marie', 'Dupont', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $marieId = (int) $db->insertID();
        $db->query("INSERT INTO db_slot_signups (slot_id, user_id, created_at, updated_at)
            VALUES ({$slotId}, {$marieId}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        // Same email tries to sign up for the same slot again
        $result = $this->csrfPost("k/ecole-dup-signup/slots/{$slotId}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@dup.fr',
            'phone'      => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('déjà', $body, 'Le message duplicate_signup doit indiquer une inscription existante');
        $this->assertStringContainsString('inscription', $body);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC3: Overlap detection with conflicting hours shown
    // ------------------------------------------------------------------

    public function testPostWithOverlappingSignupShowsOverlapMessage(): void
    {
        $kermesseId = $this->insertKermesse('ecole-overlap');
        $standId    = $this->insertStand($kermesseId);

        // Slot A: 09:00–10:30 (already signed up)
        $slotA = $this->insertSlotWithTimes($standId, '2026-09-12 09:00:00', '2026-09-12 10:30:00', 5);
        // Slot B: 10:00–11:30 (overlaps with A)
        $slotB = $this->insertSlotWithTimes($standId, '2026-09-12 10:00:00', '2026-09-12 11:30:00', 5);

        // Pre-register Marie on slot A
        $db    = db_connect();
        $email = 'marie@overlap.fr';
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Marie', 'Dupont', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $marieId = (int) $db->insertID();
        $db->query("INSERT INTO db_slot_signups (slot_id, user_id, created_at, updated_at)
            VALUES ({$slotA}, {$marieId}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        // Same email tries to sign up for slot B (overlapping)
        $result = $this->csrfPost("k/ecole-overlap/slots/{$slotB}/slot-signup", [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@overlap.fr',
            'phone'      => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('chevauche', $body, 'Le message overlap_conflict doit mentionner "chevauche"');
        // Conflicting slot A times must appear (09:00–10:30)
        $this->assertStringContainsString('09:00', $body, 'L\'heure de début du créneau conflictuel doit être affichée');
        $this->assertStringContainsString('10:30', $body, 'L\'heure de fin du créneau conflictuel doit être affichée');
    }

    // ------------------------------------------------------------------
    // Story 3.4 — Cancelled signups do not count as capacity
    // ------------------------------------------------------------------

    public function testCancelledSignupDoesNotBlockNewSignup(): void
    {
        $kermesseId = $this->insertKermesse('ecole-cancelled-cap');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 1); // capacity = 1

        // Insert a CANCELLED signup — must not count toward capacity
        $db    = db_connect();
        $email = 'alice@cancel.fr';
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Alice', 'Martin', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $aliceId = (int) $db->insertID();
        $db->query("INSERT INTO db_slot_signups (slot_id, user_id, canceled_at, canceled_by, created_at, updated_at)
            VALUES ({$slotId}, {$aliceId}, CURRENT_TIMESTAMP, {$aliceId}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $result = $this->csrfPost("k/ecole-cancelled-cap/slots/{$slotId}/slot-signup", [
            'first_name' => 'Bob',
            'last_name'  => 'Dupont',
            'email'      => 'bob@cancel.fr',
            'phone'      => '',
        ]);

        // Capacity 1, cancelled signup = 0 active → signup must succeed
        $this->assertSame(302, $result->response()->getStatusCode(), 'Une inscription annulée ne doit pas bloquer la capacité');
        $this->assertStringContainsString('/slot-signup/confirmation', $result->response()->getHeaderLine('Location'));
    }

    public function testSameUserCanReSignUpAfterCancellation(): void
    {
        $kermesseId = $this->insertKermesse('ecole-resignup');
        $standId    = $this->insertStand($kermesseId);
        $slotId     = $this->insertSlot($standId, 2);

        // Alice a une inscription annulée sur ce créneau (même user_id, même slot_id).
        $db    = db_connect();
        $email = 'alice-resignup@cancel.fr';
        $db->query("INSERT INTO db_users (email, email_hash, first_name, last_name, phone, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Alice', 'Martin', '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $aliceId = (int) $db->insertID();
        $db->query("INSERT INTO db_slot_signups (slot_id, user_id, canceled_at, canceled_by, created_at, updated_at)
            VALUES ({$slotId}, {$aliceId}, CURRENT_TIMESTAMP, {$aliceId}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        // Alice soumet à nouveau le formulaire pour le même créneau.
        $result = $this->csrfPost("k/ecole-resignup/slots/{$slotId}/slot-signup", [
            'first_name' => 'Alice',
            'last_name'  => 'Martin',
            'email'      => $email,
            'phone'      => '',
        ]);

        // Régression uq_signups_user_slot : l'inscription annulée ne doit pas bloquer
        // un second INSERT pour la même paire (user_id, slot_id).
        $this->assertSame(302, $result->response()->getStatusCode(), 'Un bénévole doit pouvoir se réinscrire après avoir annulé');
        $this->assertStringContainsString('/slot-signup/confirmation', $result->response()->getHeaderLine('Location'));
    }
}
