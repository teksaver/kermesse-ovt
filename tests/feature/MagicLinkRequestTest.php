<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for Magic Link request: GET/POST /auth/login (Story 1.3).
 *
 * Covers: form display with CSRF, invalid-email rejection with value preservation,
 * and the neutral success view returned regardless of whether the account exists.
 *
 * @internal
 */
final class MagicLinkRequestTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
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
        $db->query('DELETE FROM db_access_tokens');
        $db->query('DELETE FROM db_email_events');
        parent::tearDown();
    }

    private function csrfPost(string $url, array $data): mixed
    {
        $security                         = service('security');
        $data[$security->getTokenName()] = $security->getHash();
        return $this->post($url, $data);
    }

    // ------------------------------------------------------------------
    // GET /auth/login
    // ------------------------------------------------------------------

    public function testGetLoginFormReturns200(): void
    {
        $result = $this->get('auth/login');
        $result->assertOK();
    }

    public function testGetLoginFormContainsCsrfField(): void
    {
        $result = $this->get('auth/login');
        $body   = $result->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/name="csrf_test_name"/',
            $body,
            'Login form must include a CSRF hidden field',
        );
    }

    public function testGetLoginFormContainsEmailInput(): void
    {
        $result = $this->get('auth/login');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('for="email"', $body);
        $this->assertStringContainsString('Adresse email', $body);
    }

    // ------------------------------------------------------------------
    // POST with invalid email — AC2
    // ------------------------------------------------------------------

    public function testPostEmptyEmailShowsFieldError(): void
    {
        $result = $this->csrfPost('auth/login', ['email' => '']);
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('form-error-list', $body,
            'An empty email must display an error list');
    }

    public function testPostInvalidEmailShowsFieldError(): void
    {
        $result = $this->csrfPost('auth/login', ['email' => 'not-an-email']);
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('form-error-list', $body,
            'An invalid email must display an error list');
    }

    public function testPostInvalidEmailPreservesEnteredValue(): void
    {
        $result = $this->csrfPost('auth/login', ['email' => 'bad@@email']);
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('bad@@email', $body,
            'The entered email value must be preserved in the form on error (UX-DR27)');
    }

    // ------------------------------------------------------------------
    // POST with valid email — AC1
    // ------------------------------------------------------------------

    public function testPostValidEmailReturns200WithNeutralView(): void
    {
        $result = $this->csrfPost('auth/login', ['email' => 'valid@example.com']);
        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('boîte mail', $body,
            'A valid email must show the neutral confirmation screen');
        $this->assertStringNotContainsString('field-error', $body,
            'The neutral view must not contain error messages');
    }

    public function testPostValidEmailCreatesTokenInDb(): void
    {
        $this->csrfPost('auth/login', ['email' => 'token@example.com']);

        $db  = db_connect();
        $row = $db->query(
            "SELECT token_type, email FROM db_access_tokens WHERE email = 'token@example.com'"
        )->getRowArray();

        $this->assertNotNull($row, 'A magic_link token must be created in access_tokens');
        $this->assertSame('magic_link', $row['token_type']);
    }

    public function testPostValidEmailRecordsEmailEvent(): void
    {
        $this->csrfPost('auth/login', ['email' => 'event@example.com']);

        $db  = db_connect();
        $row = $db->query(
            "SELECT event_type, recipient_email FROM db_email_events WHERE event_type = 'magic_link'"
        )->getRowArray();

        $this->assertNotNull($row, 'A magic_link email_event must be recorded');
        $this->assertSame('event@example.com', $row['recipient_email']);
    }

    public function testPostValidEmailNeutralResponseDoesNotRevealAccountExistence(): void
    {
        // Submit two different emails — both must produce the same neutral view
        $bodyKnown   = $this->csrfPost('auth/login', ['email' => 'known@example.com'])->response()->getBody();
        $bodyUnknown = $this->csrfPost('auth/login', ['email' => 'unknown-user-xyz@example.com'])->response()->getBody();

        // Both must show the confirmation screen (NFR5, UX-DR18)
        $this->assertStringContainsString('boîte mail', $bodyKnown);
        $this->assertStringContainsString('boîte mail', $bodyUnknown);
    }

    public function testPostValidEmailNormalizesEmailToLowercase(): void
    {
        $this->csrfPost('auth/login', ['email' => 'USER@EXAMPLE.COM']);

        $db  = db_connect();
        $row = $db->query(
            "SELECT email FROM db_access_tokens WHERE email = 'user@example.com'"
        )->getRowArray();

        $this->assertNotNull($row, 'Stored email in token must be normalized to lowercase');
        $this->assertSame('user@example.com', $row['email']);
    }
}
