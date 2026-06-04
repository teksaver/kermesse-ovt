<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /admin/kermesses/{id}.
 *
 * Tests focus on access control (session-based) and non-leak guarantees.
 * Tests with an authenticated session require the owners table in the test DB.
 *
 * @internal
 */
final class AdminDashboardTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_owners');
        $db->query('DELETE FROM db_kermesses');
        parent::tearDown();
    }

    private function insertActiveOwnerAndKermesse(): array
    {
        $db = db_connect();
        $email = 'admin@example.com';
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Admin Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, 'kermesse-admin-test', 'Kermesse de Test Admin', '2026-09-01', 'Paris', 'Test admin', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = $db->insertID();

        return ['ownerId' => $ownerId, 'kermesseId' => $kermesseId];
    }

    private function insertPendingOwner(): int
    {
        $db = db_connect();
        $email = 'pending@example.com';
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Pending Owner', 'owner_pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    // ------------------------------------------------------------------
    // Access denied — no session
    // ------------------------------------------------------------------

    public function testAdminWithoutSessionRedirectsToLogin(): void
    {
        $result = $this->get('admin/kermesses/1');

        // Expect a redirect to /owner/login
        $this->assertTrue(
            $result->response()->getStatusCode() === 302
            || str_contains($result->response()->getHeaderLine('Location') ?? '', 'owner/login'),
            'Expected redirect to /owner/login without a session'
        );
    }

    public function testAdminWithoutSessionDoesNotRevealKermesseData(): void
    {
        $result = $this->get('admin/kermesses/1');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
    }

    // ------------------------------------------------------------------
    // Access denied — wrong kermesse in session
    // ------------------------------------------------------------------

    public function testAdminWithMismatchedKermesseIdShowsDenial(): void
    {
        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => 1,
            'kermesse_id'               => 999, // does not match URL param
        ])->get('admin/kermesses/1');

        // Either redirect or denial view
        $statusCode = $result->response()->getStatusCode();
        $body       = $result->response()->getBody();

        $isRedirect = in_array($statusCode, [301, 302, 303, 307, 308], true);
        $isDenial   = str_contains($body, 'Accès refusé') || str_contains($body, 'pas accès');

        $this->assertTrue($isRedirect || $isDenial,
            'Expected redirect or denial page for kermesse ID mismatch');
    }

    // ------------------------------------------------------------------
    // Validation required — owner_pending in session
    // ------------------------------------------------------------------

    public function testAdminWithPendingOwnerShowsValidationRequired(): void
    {
        // Real pending owner with a matching kermesse — strict 403 + validation email microcopy.
        $db    = db_connect();
        $email = 'pending-admin@example.com';
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Pending Admin', 'owner_pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pendingOwnerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, timezone, status, created_at, updated_at)
            VALUES ({$pendingOwnerId}, 'pending-admin-slug', 'Kermesse Pending Admin', '2026-09-01', 'Lyon', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $pendingKermesseId = (int) $db->insertID();

        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => $pendingOwnerId,
            'kermesse_id'               => $pendingKermesseId,
        ])->get('admin/kermesses/' . $pendingKermesseId);

        // Must return 403 — not a redirect
        $this->assertSame(403, $result->response()->getStatusCode(),
            'Pending owner must receive a 403 Forbidden, not a redirect');

        // Must display validation email microcopy
        $body = $result->response()->getBody();
        $this->assertTrue(
            str_contains(strtolower($body), 'validez') || str_contains(strtolower($body), 'validation'),
            'Denial page must mention email validation'
        );
    }

    // ------------------------------------------------------------------
    // No sensitive data in error pages
    // ------------------------------------------------------------------

    public function testAdminDenialPageDoesNotLeakSensitiveData(): void
    {
        $result = $this->get('admin/kermesses/1');
        $body   = $result->response()->getBody();

        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('Exception', $body);
    }

    // ------------------------------------------------------------------
    // Real owner/kermesse data — active owner sees dashboard
    // ------------------------------------------------------------------

    public function testActiveOwnerWithCorrectSessionSeesDashboard(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse();

        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => $ids['ownerId'],
            'kermesse_id'               => $ids['kermesseId'],
        ])->get('admin/kermesses/' . $ids['kermesseId']);

        $result->assertOK();
        $body = $result->response()->getBody();
        $this->assertStringContainsString('Kermesse de Test Admin', $body);
    }

    public function testActiveOwnerDashboardShowsPreparationStatus(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse();

        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => $ids['ownerId'],
            'kermesse_id'               => $ids['kermesseId'],
        ])->get('admin/kermesses/' . $ids['kermesseId']);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('préparation', strtolower($body));
    }

    // ------------------------------------------------------------------
    // Strict 403 — pending owner and kermesse mismatch
    // ------------------------------------------------------------------

    public function testPendingOwnerSessionReturnsForbidden(): void
    {
        $pendingOwnerId = $this->insertPendingOwner();

        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => $pendingOwnerId,
            'kermesse_id'               => 1,
        ])->get('admin/kermesses/1');

        $this->assertSame(403, $result->response()->getStatusCode());
    }

    public function testKermesseMismatchReturnsForbidden(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse();

        $result = $this->withSession([
            'owner_admin_authenticated' => true,
            'owner_id'                  => $ids['ownerId'],
            'kermesse_id'               => $ids['kermesseId'],
        ])->get('admin/kermesses/' . ($ids['kermesseId'] + 999));

        $this->assertSame(403, $result->response()->getStatusCode());
    }
}
