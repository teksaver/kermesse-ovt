<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /owner/validate/{token}.
 *
 * Tests focus on routing and non-leak guarantees for the "invalid token" path,
 * which exercises the full stack (controller → service → model → DB query returns null).
 * The test SQLite DB is seeded with the minimal schema in setUp.
 *
 * @internal
 */
final class OwnerValidationTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_owners');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_access_tokens');
        parent::tearDown();
    }

    private function insertPendingOwnerWithToken(string &$rawToken, bool $used = false, bool $revoked = false, bool $expired = false): array
    {
        $db    = db_connect();
        $email = 'owner-validation-test@example.com';

        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Validation Owner', 'owner_pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, 'validation-test-slug', 'Kermesse Validation', '2026-09-01', 'Nantes', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = (int) $db->insertID();

        $rawToken  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = $expired
            ? date('Y-m-d H:i:s', time() - 3600)   // in the past
            : date('Y-m-d H:i:s', time() + 86400);  // valid for 24h

        $usedAt    = $used    ? "CURRENT_TIMESTAMP" : "NULL";
        $revokedAt = $revoked ? "CURRENT_TIMESTAMP" : "NULL";

        $db->query("INSERT INTO db_access_tokens (token_hash, token_type, owner_id, kermesse_id, email, expires_at, used_at, revoked_at, created_at, updated_at)
            VALUES ('{$tokenHash}', 'owner_validation', {$ownerId}, {$kermesseId}, '{$email}', '{$expiresAt}', {$usedAt}, {$revokedAt}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        return ['ownerId' => $ownerId, 'kermesseId' => $kermesseId];
    }

    public function testInvalidTokenRespondsOkWithResultPage(): void
    {
        $result = $this->get('owner/validate/this-token-does-not-exist-in-db');

        $result->assertOK();
        $body = $result->response()->getBody();

        $this->assertStringContainsString('Ce lien n', $body); // "Ce lien n'est plus valide."
    }

    public function testValidationPageContainsLoginLink(): void
    {
        $result = $this->get('owner/validate/invalid-token-xyz');
        $body   = $result->response()->getBody();

        // The result page must offer a "me connecter" path
        $this->assertStringContainsString('owner/login', $body);
    }

    public function testValidationResultDoesNotLeakSensitiveData(): void
    {
        $result = $this->get('owner/validate/some-fake-token');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('some-fake-token', $body, 'Raw token must not appear in response');
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('Exception', $body);
    }

    public function testValidationResultDoesNotContainTokenHash(): void
    {
        $rawToken = 'some-specific-test-token';
        $hash     = hash('sha256', $rawToken);

        $result = $this->get('owner/validate/' . $rawToken);
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString($hash, $body, 'Token hash must not appear in response');
    }

    // ------------------------------------------------------------------
    // Successful validation — activation, session, redirection
    // ------------------------------------------------------------------

    public function testValidTokenActivatesOwnerAndRedirectsToAdmin(): void
    {
        $rawToken = '';
        $ids      = $this->insertPendingOwnerWithToken($rawToken);

        $result = $this->get('owner/validate/' . $rawToken);

        // Must redirect to the admin kermesse page
        $statusCode = $result->response()->getStatusCode();
        $location   = $result->response()->getHeaderLine('Location');

        $this->assertTrue(
            $statusCode === 302 || str_contains($location, 'admin/kermesses/' . $ids['kermesseId']),
            "Expected redirect to admin/kermesses/{$ids['kermesseId']}, got {$statusCode} {$location}"
        );

        $db = db_connect();

        // Owner must now be active in the DB
        $ownerRow = $db->query("SELECT status, email_verified_at FROM db_owners WHERE id = {$ids['ownerId']}")->getRow();
        $this->assertSame('active', $ownerRow->status, 'Owner must be activated after valid token use');
        $this->assertNotNull($ownerRow->email_verified_at, 'email_verified_at must be set on activation');

        // Token must be marked as used
        $tokenRow = $db->query(
            "SELECT used_at FROM db_access_tokens WHERE token_hash = '" . hash('sha256', $rawToken) . "'"
        )->getRow();
        $this->assertNotNull($tokenRow->used_at, 'Token must be marked used_at after validation');
    }

    public function testTokenCannotBeReusedAfterSuccessfulValidation(): void
    {
        $rawToken = '';
        $this->insertPendingOwnerWithToken($rawToken);

        // First use — activates the owner
        $this->get('owner/validate/' . $rawToken);

        // Second use — must show "Lien déjà utilisé"
        $result = $this->get('owner/validate/' . $rawToken);

        $result->assertOK();
        $body = $result->response()->getBody();
        $this->assertStringContainsString('déjà été utilisé', $body,
            'Reused token must show a "already used" message');
    }

    public function testExistingAdminSessionIsPreservedOnValidationFailure(): void
    {
        // An already-authenticated owner must not lose their session if a (different or stale)
        // validation link fails. Only stale/partial session keys with no auth flag should be removed.
        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => 99,
            'kermesse_id'               => 88,
        ])->get('owner/validate/invalid-token-that-does-not-exist-in-db');

        $result->assertOK();

        // Existing admin session keys must still be present
        $result->assertSessionHas('owner_admin_authenticated', true);
    }

    // ------------------------------------------------------------------
    // Failure microcopy — used, revoked, expired
    // ------------------------------------------------------------------

    public function testUsedTokenShowsAlreadyUsedMicrocopy(): void
    {
        $rawToken = '';
        $this->insertPendingOwnerWithToken($rawToken, used: true);

        $result = $this->get('owner/validate/' . $rawToken);

        $result->assertOK();
        $body = $result->response()->getBody();
        $this->assertStringContainsString('déjà été utilisé', $body);
        $result->assertSessionMissing('owner_admin_authenticated');
    }

    public function testRevokedTokenShowsInvalidMicrocopy(): void
    {
        $rawToken = '';
        $this->insertPendingOwnerWithToken($rawToken, revoked: true);

        $result = $this->get('owner/validate/' . $rawToken);

        $result->assertOK();
        $body = $result->response()->getBody();
        $this->assertStringContainsString("Ce lien n", $body); // "Ce lien n'est plus valide"
        $result->assertSessionMissing('owner_admin_authenticated');
    }

    public function testExpiredTokenForPendingOwnerShowsExpirationWithLoginLink(): void
    {
        $rawToken = '';
        $this->insertPendingOwnerWithToken($rawToken, expired: true);

        $result = $this->get('owner/validate/' . $rawToken);

        $result->assertOK();
        $body = $result->response()->getBody();
        $this->assertStringContainsString('expiré', $body,
            'Expired token for pending owner must show expiration message');
        $this->assertStringContainsString('owner/login', $body,
            'Expired token page must offer the login/resend link');
        $result->assertSessionMissing('owner_admin_authenticated');
    }

    public function testExpiredTokenPageShowsMeConnecterLabel(): void
    {
        $rawToken = '';
        $this->insertPendingOwnerWithToken($rawToken, expired: true);

        $result = $this->get('owner/validate/' . $rawToken);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Me connecter', $body,
            'Expired link page must explicitly show the "Me connecter" label (AC3)');
    }
}
