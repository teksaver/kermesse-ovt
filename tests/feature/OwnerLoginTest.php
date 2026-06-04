<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /owner/login and POST /owner/login.
 *
 * POST tests that reach the service layer require the owners table in the test DB.
 * The test SQLite DB is seeded with the minimal schema in setUp.
 *
 * @internal
 */
final class OwnerLoginTest extends CIUnitTestCase
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
            CREATE TABLE IF NOT EXISTS db_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                token_type TEXT NOT NULL,
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
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_owners');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_access_tokens');
        $db->query('DELETE FROM db_email_events');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // GET /owner/login
    // ------------------------------------------------------------------

    public function testLoginFormRespondsOk(): void
    {
        $result = $this->get('owner/login');
        $result->assertOK();
    }

    public function testLoginFormContainsEmailField(): void
    {
        $result = $this->get('owner/login');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('name="owner_email"', $body);
        $this->assertStringContainsString('for="field-owner_email"', $body);
        $this->assertStringContainsString('id="field-owner_email"', $body);
    }

    public function testLoginFormContainsCsrfToken(): void
    {
        $result = $this->get('owner/login');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('csrf_test_name', $body);
    }

    public function testLoginFormShowsVisibleLabel(): void
    {
        $result = $this->get('owner/login');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Votre adresse email', $body);
    }

    // ------------------------------------------------------------------
    // POST /owner/login — validation errors
    // ------------------------------------------------------------------

    public function testEmptyEmailShowsRequiredError(): void
    {
        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => '',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Veuillez saisir votre adresse email', $body);
    }

    public function testInvalidEmailFormatShowsError(): void
    {
        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => 'not-an-email',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('adresse email valide', $body);
    }

    public function testInvalidEmailPreservesValue(): void
    {
        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => 'bad-email',
        ]);

        $body = $result->response()->getBody();

        $this->assertStringContainsString('bad-email', $body);
    }

    // ------------------------------------------------------------------
    // POST /owner/login — valid email (no DB match expected)
    // ------------------------------------------------------------------

    public function testValidEmailAlwaysShowsNeutralConfirmation(): void
    {
        // Valid email format, but no owner in DB → neutral message
        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => 'nobody@unknown-domain.example',
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        // Must show neutral confirmation
        $this->assertStringContainsString('Vérifiez votre email', $body);
    }

    public function testLoginConfirmationDoesNotRevealAccountExistence(): void
    {
        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => 'nonexistent@example.com',
        ]);

        $body = $result->response()->getBody();

        // Must NOT say the email was not found
        $this->assertStringNotContainsString('aucun compte', strtolower($body));
        $this->assertStringNotContainsString('inconnu', strtolower($body));
        $this->assertStringNotContainsString('not found', strtolower($body));
    }

    // ------------------------------------------------------------------
    // Security: no sensitive data in responses
    // ------------------------------------------------------------------

    public function testLoginFormDoesNotLeakSensitiveData(): void
    {
        $result = $this->get('owner/login');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.env', $body);
    }

    // ------------------------------------------------------------------
    // POST /owner/login — pending owner resend with real DB
    // ------------------------------------------------------------------

    public function testPendingOwnerResendCreatesHashedTokenAndShowsNeutralConfirmation(): void
    {
        $db    = db_connect();
        $email = 'pending-resend@example.com';
        $hash  = hash('sha256', $email);

        // Insert pending owner
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, created_at, updated_at)
            VALUES ('{$email}', '{$hash}', 'Test Owner', 'owner_pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        // Insert kermesse for this owner
        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, 'resend-test-slug', 'Kermesse Resend', '2026-09-01', 'Lyon', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        // Insert an "old" active token outside the cooldown window to confirm it gets revoked.
        // created_at is set to 10 minutes ago so hasRecentActiveOwnerValidationToken() won't
        // block the resend flow (cooldown is 5 minutes).
        $oldRawToken  = 'old-token-raw-' . bin2hex(random_bytes(4));
        $oldHash      = hash('sha256', $oldRawToken);
        $expiresAt    = date('Y-m-d H:i:s', time() + 3600);
        $oldCreatedAt = date('Y-m-d H:i:s', time() - 600);
        $db->query("INSERT INTO db_access_tokens (token_hash, token_type, owner_id, email, expires_at, created_at, updated_at)
            VALUES ('{$oldHash}', 'owner_validation', {$ownerId}, '{$email}', '{$expiresAt}', '{$oldCreatedAt}', '{$oldCreatedAt}')");

        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => $email,
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        // Neutral confirmation must appear
        $this->assertStringContainsString('Vérifiez votre email', $body);

        // A new token must have been created in the DB
        $newTokenRow = $db->query(
            "SELECT id, token_hash FROM db_access_tokens WHERE owner_id = {$ownerId} AND token_hash != '{$oldHash}' ORDER BY id DESC LIMIT 1"
        )->getRow();
        $this->assertNotNull($newTokenRow, 'A new hashed token must exist after resend');

        // New token hash must be a 64-char hex SHA-256 (raw token is never stored)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $newTokenRow->token_hash,
            'New token must be stored as a SHA-256 hash, not raw');

        // Old token must be revoked (revokeOlderActiveOwnerValidationTokens uses id <, not hash)
        $oldRow = $db->query("SELECT revoked_at FROM db_access_tokens WHERE token_hash = '{$oldHash}'")->getRow();
        $this->assertNotNull($oldRow->revoked_at, 'Old token must be revoked after resend');
    }

    // ------------------------------------------------------------------
    // POST /owner/login — active owner branch (Story 1.6)
    // ------------------------------------------------------------------

    public function testActiveOwnerPostCreatesOwnerLoginTokenNotValidationToken(): void
    {
        $db    = db_connect();
        $email = 'active-owner@example.com';
        $hash  = hash('sha256', $email);

        // Insert an active owner
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '{$hash}', 'Active Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        // Insert kermesse for this owner
        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, 'active-slug', 'Ma Kermesse Active', '2026-09-01', 'Paris', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $result = $this->post('owner/login', [
            'csrf_test_name' => csrf_hash(),
            'owner_email'    => $email,
        ]);

        $result->assertOK();
        $body = $result->response()->getBody();

        // Neutral confirmation must appear (anti-enumeration)
        $this->assertStringContainsString('Vérifiez votre email', $body);

        // An owner_login token must exist in the DB (issued then possibly revoked due to no SMTP)
        $loginTokenRow = $db->query(
            "SELECT id, token_hash, token_type FROM db_access_tokens WHERE owner_id = {$ownerId} AND token_type = 'owner_login' LIMIT 1"
        )->getRow();
        $this->assertNotNull($loginTokenRow, 'An owner_login token must be created for an active owner');
        $this->assertSame('owner_login', $loginTokenRow->token_type);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $loginTokenRow->token_hash,
            'Login token must be stored as SHA-256 hash, not raw');

        // No owner_validation token must have been created
        $validationTokenRow = $db->query(
            "SELECT id FROM db_access_tokens WHERE owner_id = {$ownerId} AND token_type = 'owner_validation' LIMIT 1"
        )->getRow();
        $this->assertNull($validationTokenRow, 'Active owner must not receive an owner_validation token');
    }

    public function testLoginPostWithoutCsrfIsRefused(): void
    {
        // CI4 CSRF protection throws a SecurityException when the token is absent.
        // FeatureTestTrait may not wrap it in a Response; catch both outcomes.
        $statusCode = 200;

        try {
            $result    = $this->post('owner/login', [
                'owner_email' => 'valid@example.com',
                // deliberately omitting csrf_test_name
            ]);
            $statusCode = $result->response()->getStatusCode();
        } catch (\CodeIgniter\Security\Exceptions\SecurityException $e) {
            // CSRF rejection thrown as exception — acceptable outcome
            $this->addToAssertionCount(1);
            return;
        }

        // CodeIgniter returns 403 or redirects on CSRF failure
        $this->assertNotSame(200, $statusCode, 'POST without CSRF must not return 200');
    }
}
