<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /owner/login/{token} (confirmation page)
 * and POST /owner/login/{token} (token consumption).
 *
 * GET shows a confirmation page so email-scanner prefetch does not consume the token.
 * POST performs the actual consumption.
 *
 * @internal
 */
final class OwnerLoginConsumptionTest extends CIUnitTestCase
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertActiveOwnerWithKermesse(string $email = 'active@example.com'): array
    {
        $db   = db_connect();
        $hash = hash('sha256', $email);

        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '{$hash}', 'Active Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, 'test-kermesse-" . bin2hex(random_bytes(4)) . "', 'Ma Kermesse', '2026-09-01', 'Paris', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = (int) $db->insertID();

        return compact('ownerId', 'kermesseId', 'email');
    }

    private function insertOwnerLoginToken(int $ownerId, int $kermesseId, string $email, int $ttl = 900): array
    {
        $db        = db_connect();
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $db->query("INSERT INTO db_access_tokens (token_hash, token_type, owner_id, kermesse_id, email, expires_at, created_at, updated_at)
            VALUES ('{$tokenHash}', 'owner_login', {$ownerId}, {$kermesseId}, '{$email}', '{$expiresAt}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $tokenId = (int) $db->insertID();

        return compact('rawToken', 'tokenHash', 'tokenId');
    }

    // ------------------------------------------------------------------
    // GET — confirmation page (prefetch protection)
    // ------------------------------------------------------------------

    public function testGetValidTokenShowsConfirmationPage(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $result = $this->get('owner/login/' . $rawToken);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('Se connecter', $body);
    }

    public function testGetConfirmationPageHasCsrfField(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $result = $this->get('owner/login/' . $rawToken);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('csrf_test_name', $body,
            'Confirmation page must contain CSRF field');
    }

    public function testGetConfirmationPageHasLinkBackToLoginForm(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $result = $this->get('owner/login/' . $rawToken);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('owner/login', $body,
            'Confirmation page must link back to the login form');
    }

    public function testGetInvalidTokenShowsLoginResultPage(): void
    {
        $result = $this->get('owner/login/completely-unknown-token');

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('Se connecter', $body,
            'Invalid token at GET must not show the confirmation button');
        $this->assertStringContainsString('owner/login', $body,
            'Error page must link back to the login form');
    }

    public function testGetConfirmationPageDoesNotContainRawTokenInHtml(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $result = $this->get('owner/login/' . $rawToken);

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString($rawToken, $body,
            'Raw token must not appear in the HTML source of the confirmation page');
    }

    public function testGetConfirmationStoresOnlyTokenIdInSession(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken, 'tokenId' => $tokenId] =
            $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->get('owner/login/' . $rawToken);

        $this->assertNull(session()->get('pending_login_token'),
            'Raw login token must never be stored in the session');
        $this->assertSame($tokenId, (int) session()->get('pending_login_token_id'),
            'Only the prevalidated token id may be stored in the session');
    }

    public function testGetRequestDoesNotConsumeToken(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken, 'tokenId' => $tokenId] =
            $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->get('owner/login/' . $rawToken);

        $db  = db_connect();
        $row = $db->query("SELECT used_at FROM db_access_tokens WHERE id = {$tokenId}")->getRow();
        $this->assertNull($row->used_at,
            'GET request must not consume the token (prefetch-scanner protection)');
    }

    public function testGetInvalidTokenDoesNotPurgeExistingAdminSession(): void
    {
        $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => 999,
            'kermesse_id'               => 888,
        ]);

        $this->get('owner/login/completely-unknown-token');

        $this->assertTrue(session()->get('owner_admin_authenticated') === true,
            'GET error page must not mutate an existing admin session');
        $this->assertSame(999, (int) session()->get('owner_id'));
        $this->assertSame(888, (int) session()->get('kermesse_id'));
    }

    public function testGetInvalidTokenClearsPendingLoginState(): void
    {
        $this->withSession(['pending_login_token_id' => 123, 'pending_login_token' => 'raw-secret']);

        $this->get('owner/login/completely-unknown-token');

        $this->assertNull(session()->get('pending_login_token_id'),
            'GET error page must clear any pending login token id');
        $this->assertNull(session()->get('pending_login_token'),
            'GET error page must clear legacy raw pending token state');
    }

    public function testValidGetThenInvalidGetPreventsConfirmingOldPendingToken(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->get('owner/login/' . $rawToken);
        $this->get('owner/login/completely-unknown-token');

        $result = $this->post('owner/login/confirm', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString("n'est plus valide", $body);
        $this->assertNotTrue(session()->get('owner_admin_authenticated'),
            'Old pending login state must not survive a later invalid GET');
    }

    public function testGetExpiredTokenShowsExpiredResultPage(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email, -1);

        $result = $this->get('owner/login/' . $rawToken);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('expiré', $body);
        $this->assertStringNotContainsString('Se connecter', $body);
        $this->assertStringContainsString('Demander un nouveau lien', $body);
    }

    public function testGetUsedTokenShowsUsedResultPage(): void
    {
        $db = db_connect();
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken, 'tokenId' => $tokenId] =
            $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);
        $db->query("UPDATE db_access_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = {$tokenId}");

        $result = $this->get('owner/login/' . $rawToken);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('déjà été utilisé', $body);
        $this->assertStringNotContainsString('Se connecter', $body);
        $this->assertStringContainsString('Demander un nouveau lien', $body);
    }

    public function testGetRevokedTokenShowsInvalidResultPage(): void
    {
        $db = db_connect();
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken, 'tokenId' => $tokenId] =
            $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);
        $db->query("UPDATE db_access_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = {$tokenId}");

        $result = $this->get('owner/login/' . $rawToken);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString("n'est plus valide", $body);
        $this->assertStringNotContainsString('Se connecter', $body);
        $this->assertStringContainsString('Demander un nouveau lien', $body);
    }

    // ------------------------------------------------------------------
    // POST — success path
    // ------------------------------------------------------------------

    public function testValidTokenRedirectsToAdminDashboard(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $result = $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertRedirectTo(site_url('admin/kermesses/' . $kermesseId));
    }

    public function testValidTokenMarksTokenAsUsed(): void
    {
        $db = db_connect();
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken, 'tokenId' => $tokenId] =
            $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $row = $db->query("SELECT used_at FROM db_access_tokens WHERE id = {$tokenId}")->getRow();
        $this->assertNotNull($row->used_at, 'Token must be marked used_at after successful consumption');
    }

    public function testValidTokenSetsAdminSessionKeys(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $this->assertTrue(session()->get('owner_admin_authenticated') === true,
            'owner_admin_authenticated must be true after successful consumption');
        $this->assertSame($ownerId, (int) session()->get('owner_id'));
        $this->assertSame($kermesseId, (int) session()->get('kermesse_id'));
    }

    public function testSessionRegeneratesOnSuccessfulLogin(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        /** @var \CodeIgniter\Test\Mock\MockSession $session */
        $session = session();
        $this->assertTrue($session->didRegenerate,
            'Session must be regenerated on successful login (anti session-fixation)');
    }

    // ------------------------------------------------------------------
    // POST — token reuse prevention
    // ------------------------------------------------------------------

    public function testUsedTokenCannotBeReused(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        // Consume the token a first time
        $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        // Try to reuse the same token
        $result = $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('déjà été utilisé', $body,
            'Second use of same token must show used microcopy');
    }

    public function testUsedTokenDoesNotCreateSecondSession(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        // Consume the token once (session created)
        $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        // Wipe session to simulate a fresh browser, then try again
        session()->destroy();

        $result = $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        // After failed consumption the controller purges admin session keys
        $this->assertNotTrue(session()->get('owner_admin_authenticated'),
            'Admin session must not exist after reused token rejection');
    }

    // ------------------------------------------------------------------
    // POST — invalid / expired / revoked token error pages
    // ------------------------------------------------------------------

    public function testInvalidTokenShowsInvalidMicrocopy(): void
    {
        $result = $this->post('owner/login/completely-unknown-token-that-does-not-exist', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString("n'est plus valide", $body);
        $this->assertStringContainsString('Demander un nouveau lien', $body);
    }

    public function testExpiredTokenShowsExpiredMicrocopy(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();

        // Insert an already-expired token (ttl = -1 means expires_at is in the past)
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email, -1);

        $result = $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('expiré', $body);
    }

    public function testRevokedTokenShowsInvalidMicrocopy(): void
    {
        $db = db_connect();
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken, 'tokenId' => $tokenId] =
            $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        // Revoke the token manually
        $db->query("UPDATE db_access_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = {$tokenId}");

        $result = $this->post('owner/login/' . $rawToken, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString("n'est plus valide", $body);
    }

    // ------------------------------------------------------------------
    // POST — session purge on failure
    // ------------------------------------------------------------------

    public function testFailedConsumptionPurgesAdminSessionKeys(): void
    {
        // Pre-load a stale admin session (simulates a leftover session)
        $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => 999,
            'kermesse_id'               => 888,
        ]);

        $result = $this->post('owner/login/completely-invalid-token', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $this->assertNotTrue(session()->get('owner_admin_authenticated'),
            'owner_admin_authenticated must be purged on failed consumption');
        $this->assertNull(session()->get('owner_id'),
            'owner_id must be purged on failed consumption');
        $this->assertNull(session()->get('kermesse_id'),
            'kermesse_id must be purged on failed consumption');
    }

    // ------------------------------------------------------------------
    // POST — security: no raw token or sensitive data in error response body
    // ------------------------------------------------------------------

    public function testInvalidTokenResponseDoesNotLeakSensitiveData(): void
    {
        $tokenValue = 'some-token-value';

        $result = $this->post('owner/login/' . $tokenValue, [
            'csrf_test_name' => csrf_hash(),
        ]);

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('token_hash', $body);
        // Raw token value must not appear in the error result page body
        $this->assertStringNotContainsString($tokenValue, $body,
            'Raw token must not appear in the error response body');
    }

    public function testLoginResultPageLinksBackToLoginForm(): void
    {
        $result = $this->post('owner/login/invalid-token-for-link-check', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString('owner/login', $body,
            'Error result page must link back to the login form');
    }

    // ------------------------------------------------------------------
    // POST /owner/login/confirm — session-based confirmation flow
    // ------------------------------------------------------------------

    public function testConfirmLoginViaSessionSucceeds(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['tokenId' => $tokenId] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->withSession(['pending_login_token_id' => $tokenId]);
        $result = $this->post('owner/login/confirm', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertRedirectTo(site_url('admin/kermesses/' . $kermesseId));
        $this->assertTrue(session()->get('owner_admin_authenticated') === true);
    }

    public function testGetPreparedTokenIdThenConfirmLoginSucceedsWithoutRawTokenInSession(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['rawToken' => $rawToken] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->get('owner/login/' . $rawToken);
        $this->assertNull(session()->get('pending_login_token'));
        $pendingTokenId = session()->get('pending_login_token_id');
        $this->assertNotNull($pendingTokenId);

        $this->withSession(['pending_login_token_id' => $pendingTokenId]);
        $result = $this->post('owner/login/confirm', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertRedirectTo(site_url('admin/kermesses/' . $kermesseId));
        $this->assertTrue(session()->get('owner_admin_authenticated') === true);
    }

    public function testConfirmLoginWithoutPendingSessionTokenShowsInvalidPage(): void
    {
        $result = $this->post('owner/login/confirm', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertStatus(200);
        $body = $result->response()->getBody();
        $this->assertStringContainsString("n'est plus valide", $body,
            'Missing session token must show the invalid-token error page');
        $this->assertNotTrue(session()->get('owner_admin_authenticated'));
    }

    public function testConfirmLoginClearsPendingSessionTokenAfterUse(): void
    {
        ['ownerId' => $ownerId, 'kermesseId' => $kermesseId, 'email' => $email] =
            $this->insertActiveOwnerWithKermesse();
        ['tokenId' => $tokenId] = $this->insertOwnerLoginToken($ownerId, $kermesseId, $email);

        $this->withSession(['pending_login_token_id' => $tokenId]);
        $this->post('owner/login/confirm', ['csrf_test_name' => csrf_hash()]);

        $this->assertNull(session()->get('pending_login_token_id'),
            'pending_login_token_id must be cleared from session after consumption');
        $this->assertNull(session()->get('pending_login_token'),
            'legacy raw pending_login_token must be cleared from session after consumption');
    }
}
