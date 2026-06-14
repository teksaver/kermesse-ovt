<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for Magic Link verification: GET /auth/magic-link/{token} (Story 1.4).
 *
 * Covers: valid token → session + redirect; invalid/expired/used/revoked → neutral error view.
 *
 * @internal
 */
final class MagicLinkVerifyTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_profile_divergences');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_access_tokens');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
                user_id      INTEGER NOT NULL,
                role         TEXT    NOT NULL,
                invited_by   INTEGER,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_access_tokens (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash  TEXT NOT NULL UNIQUE,
                token_type  TEXT NOT NULL,
                user_id     INTEGER,
                owner_id    INTEGER,
                kermesse_id INTEGER,
                email       TEXT,
                expires_at  DATETIME NOT NULL,
                used_at     DATETIME,
                revoked_at  DATETIME,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_profile_divergences (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id              INTEGER NOT NULL,
                kermesse_id          INTEGER NOT NULL,
                signup_id            INTEGER,
                submitted_first_name TEXT    NOT NULL DEFAULT "",
                submitted_last_name  TEXT    NOT NULL DEFAULT "",
                submitted_phone      TEXT    NOT NULL DEFAULT "",
                last_login_at DATETIME NULL DEFAULT NULL,
                resolved_at          DATETIME,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    /**
     * Insert a magic_link token directly into the DB and return the raw token.
     *
     * @param array<string, mixed> $overrides
     */
    private function insertMagicLinkToken(string $email, array $overrides = []): string
    {
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');

        $defaults = [
            'token_hash'  => hash('sha256', $rawToken),
            'token_type'  => 'magic_link',
            'email'       => $email,
            'expires_at'  => date('Y-m-d H:i:s', time() + 900),
            'used_at'     => null,
            'revoked_at'  => null,
        ];

        $row = array_merge($defaults, $overrides);

        db_connect()->table('access_tokens')->insert($row);

        return $rawToken;
    }

    // ------------------------------------------------------------------
    // AC1 — valid token: redirect + session + user creation/reuse
    // ------------------------------------------------------------------

    public function testValidTokenRedirectsToHome(): void
    {
        $rawToken = $this->insertMagicLinkToken('alice@example.com');
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $result->assertRedirectTo(site_url('/'));
    }

    public function testValidTokenWithKermesseIntentRedirectsToDashboard(): void
    {
        $db    = db_connect();
        $email = 'owner-intent@example.com';

        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => 'Owner',
            'last_name'  => 'Intent',
            'phone'      => '',
        ]);
        $userId = (int) $db->insertID();

        $db->table('kermesses')->insert([
            'created_by'        => $userId,
            'public_slug'       => 'intent-kermesse',
            'name'              => 'Kermesse Intent',
            'event_date'        => '2026-09-15',
            'location'          => 'Salle',
            'short_description' => '',
            'status'            => 'preparation',
        ]);
        $kermesseId = (int) $db->insertID();

        $db->table('kermesse_user_roles')->insert([
            'kermesse_id' => $kermesseId,
            'user_id'     => $userId,
            'role'        => 'owner',
        ]);

        $rawToken = $this->insertMagicLinkToken($email, ['kermesse_id' => $kermesseId]);
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $result->assertRedirectTo(site_url('kermesse/' . $kermesseId));
    }

    public function testValidTokenEstablishesSession(): void
    {
        $rawToken = $this->insertMagicLinkToken('bob@example.com');
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $user = db_connect()->table('users')->where('email', 'bob@example.com')->get()->getRowArray();

        $result->assertSessionHas('is_logged_in', true);
        $result->assertSessionHas('user_id', (int) $user['id']);
    }

    public function testValidTokenCreatesUserWhenUnknown(): void
    {
        $rawToken = $this->insertMagicLinkToken('newuser@example.com');
        $this->get('auth/magic-link/' . $rawToken);

        $row = db_connect()
            ->query("SELECT email FROM db_users WHERE email = 'newuser@example.com'")
            ->getRowArray();

        $this->assertNotNull($row, 'A user row must be created for an unknown email');
        $this->assertSame('newuser@example.com', $row['email']);
    }

    public function testValidTokenReusesExistingUser(): void
    {
        $email     = 'existing@example.com';
        $emailHash = hash('sha256', $email);

        db_connect()->table('users')->insert([
            'email'      => $email,
            'email_hash' => $emailHash,
            'first_name' => 'Existing',
            'last_name'  => 'User',
            'phone'      => '',
        ]);

        $rawToken = $this->insertMagicLinkToken($email);
        $this->get('auth/magic-link/' . $rawToken);

        $count = (int) db_connect()
            ->query("SELECT COUNT(*) AS cnt FROM db_users WHERE email = '{$email}'")
            ->getRowArray()['cnt'];

        $this->assertSame(1, $count, 'Only one user row must exist for this email after login');
    }

    public function testValidTokenMarksTokenAsUsed(): void
    {
        $rawToken = $this->insertMagicLinkToken('mark@example.com');
        $this->get('auth/magic-link/' . $rawToken);

        $row = db_connect()
            ->query("SELECT used_at FROM db_access_tokens WHERE token_hash = '" . hash('sha256', $rawToken) . "'")
            ->getRowArray();

        $this->assertNotNull($row['used_at'], 'Token must be marked used_at after a successful login');
    }

    public function testUsedTokenCannotBeReusedForLogin(): void
    {
        $rawToken = $this->insertMagicLinkToken('replay@example.com');

        // First use
        $this->get('auth/magic-link/' . $rawToken);

        // Replay attempt — must not create a second session or crash
        $result = $this->get('auth/magic-link/' . $rawToken);
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Lien invalide', $body,
            'A replay of a used token must show the neutral error view');
    }

    // ------------------------------------------------------------------
    // AC2 — invalid / expired / used / revoked → neutral error view
    // ------------------------------------------------------------------

    public function testExpiredTokenShowsNeutralError(): void
    {
        $rawToken = $this->insertMagicLinkToken('exp@example.com', [
            'expires_at' => date('Y-m-d H:i:s', time() - 1),
        ]);

        $result = $this->get('auth/magic-link/' . $rawToken);
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Lien invalide', $body,
            'An expired token must show the neutral error view (UX-DR18)');
        $this->assertStringNotContainsString('redirect', strtolower($body));
    }

    public function testUsedTokenShowsNeutralError(): void
    {
        $rawToken = $this->insertMagicLinkToken('used@example.com', [
            'used_at' => date('Y-m-d H:i:s', time() - 60),
        ]);

        $result = $this->get('auth/magic-link/' . $rawToken);
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Lien invalide', $body);
    }

    public function testRevokedTokenShowsNeutralError(): void
    {
        $rawToken = $this->insertMagicLinkToken('revoked@example.com', [
            'revoked_at' => date('Y-m-d H:i:s', time() - 60),
        ]);

        $result = $this->get('auth/magic-link/' . $rawToken);
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Lien invalide', $body);
    }

    public function testUnknownTokenShowsNeutralError(): void
    {
        $result = $this->get('auth/magic-link/totally-unknown-garbage-token');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Lien invalide', $body);
    }

    public function testNeutralErrorViewProposesNewLinkRequest(): void
    {
        $result = $this->get('auth/magic-link/invalid-token');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('auth/login', $body,
            'The error view must offer a link to request a new Magic Link (UX-DR18)');
    }

    public function testErrorViewDoesNotRevealAccountExistence(): void
    {
        // Token for a known email that has been used
        $rawToken = $this->insertMagicLinkToken('known@example.com', [
            'used_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $bodyKnown = $this->get('auth/magic-link/' . $rawToken)->response()->getBody();

        // Totally fabricated (unknown) token
        $bodyUnknown = $this->get('auth/magic-link/absolutely-made-up-token')->response()->getBody();

        // Both responses must be indistinguishable — no account existence hint
        $this->assertStringNotContainsString('known@example.com', $bodyKnown);
        $this->assertStringNotContainsString('known@example.com', $bodyUnknown);
        $this->assertStringContainsString('Lien invalide', $bodyKnown);
        $this->assertStringContainsString('Lien invalide', $bodyUnknown);
    }
}
