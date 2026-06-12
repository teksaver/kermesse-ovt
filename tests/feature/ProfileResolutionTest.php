<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for Story 3.6 — Profile divergence resolution at login.
 *
 * Covers:
 * - Detection: Magic Link login with pending divergences → redirect to resolution page
 * - Clean login: no divergences → redirect to home directly
 * - Resolution with 'submitted': profile updated, divergences resolved, redirect home
 * - Resolution with 'keep': profile unchanged, divergences resolved, redirect home
 * - Bypass prevention: GET / with pending_profile_resolution flag → redirect to resolution
 *
 * @internal
 */
final class ProfileResolutionTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_profile_divergences');
        $db->query('DELETE FROM db_access_tokens');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Table setup
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
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
                resolved_at          DATETIME,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertUser(
        string $email,
        string $firstName = 'Alice',
        string $lastName  = 'Martin',
        string $phone     = '0611111111',
    ): int {
        $db = db_connect();
        $db->table('users')->insert([
            'email'      => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
        ]);

        return (int) $db->insertID();
    }

    private function insertMagicLinkToken(string $email, array $overrides = []): string
    {
        $rawBytes = random_bytes(32);
        $rawToken = rtrim(strtr(base64_encode($rawBytes), '+/', '-_'), '=');

        $row = array_merge([
            'token_hash' => hash('sha256', $rawToken),
            'token_type' => 'magic_link',
            'email'      => $email,
            'expires_at' => date('Y-m-d H:i:s', time() + 900),
            'used_at'    => null,
            'revoked_at' => null,
        ], $overrides);

        db_connect()->table('access_tokens')->insert($row);

        return $rawToken;
    }

    private function insertDivergence(
        int    $userId,
        string $submittedFirst = 'Alice',
        string $submittedLast  = 'MARTIN',
        string $submittedPhone = '0699999999',
    ): int {
        $db = db_connect();
        $db->table('profile_divergences')->insert([
            'user_id'              => $userId,
            'kermesse_id'          => 1,
            'signup_id'            => null,
            'submitted_first_name' => $submittedFirst,
            'submitted_last_name'  => $submittedLast,
            'submitted_phone'      => $submittedPhone,
            'resolved_at'          => null,
        ]);

        return (int) $db->insertID();
    }

    /** @return array<string, mixed> */
    private function connectedSession(int $userId, bool $pendingResolution = false): array
    {
        $session = ['user_id' => $userId, 'is_logged_in' => true];
        if ($pendingResolution) {
            $session['pending_profile_resolution'] = true;
        }

        return $session;
    }

    private function csrfPost(string $url, array $data = []): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->post($url, $data);
    }

    private function csrfPostWithSession(string $url, array $sessionData, array $data = []): mixed
    {
        $security                        = service('security');
        $data[$security->getTokenName()] = $security->getHash();

        return $this->withSession($sessionData)->post($url, $data);
    }

    // ------------------------------------------------------------------
    // Detection — AC1 (login with divergences → redirect to resolution)
    // ------------------------------------------------------------------

    public function testMagicLinkLoginWithDivergencesRedirectsToResolution(): void
    {
        $email    = 'alice@example.com';
        $userId   = $this->insertUser($email);
        $this->insertDivergence($userId);

        $rawToken = $this->insertMagicLinkToken($email);
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $result->assertRedirectTo(site_url('auth/profile-resolution'));
    }

    public function testMagicLinkLoginWithDivergencesSetsSessionFlag(): void
    {
        $email  = 'flag@example.com';
        $userId = $this->insertUser($email);
        $this->insertDivergence($userId);

        $rawToken = $this->insertMagicLinkToken($email);
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $result->assertSessionHas('pending_profile_resolution', true);
    }

    // ------------------------------------------------------------------
    // Clean login — AC1 (no divergences → redirect to home)
    // ------------------------------------------------------------------

    public function testMagicLinkLoginWithNoDivergencesRedirectsToHome(): void
    {
        $email    = 'clean@example.com';
        $this->insertUser($email);

        $rawToken = $this->insertMagicLinkToken($email);
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $result->assertRedirectTo(site_url('/'));
    }

    public function testMagicLinkLoginWithNoDivergencesDoesNotSetFlag(): void
    {
        $email    = 'noflag@example.com';
        $this->insertUser($email);

        $rawToken = $this->insertMagicLinkToken($email);
        $result   = $this->get('auth/magic-link/' . $rawToken);

        $result->assertSessionMissing('pending_profile_resolution');
    }

    // ------------------------------------------------------------------
    // Resolution with 'submitted' — AC1 (profile updated, divergences resolved)
    // ------------------------------------------------------------------

    public function testResolvingWithSubmittedUpdatesProfile(): void
    {
        $email  = 'resolve-sub@example.com';
        $userId = $this->insertUser($email, 'Alice', 'Martin', '0611111111');
        $this->insertDivergence($userId, 'Alice', 'MARTIN', '0699999999');

        $session = $this->connectedSession($userId, true);
        $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'submitted']);

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('MARTIN', $user['last_name'], 'Last name must be updated when choice is submitted');
        $this->assertSame('0699999999', $user['phone'], 'Phone must be updated when choice is submitted');
    }

    public function testResolvingWithSubmittedMarksDivergencesResolved(): void
    {
        $email  = 'resolve-sub2@example.com';
        $userId = $this->insertUser($email);
        $divId  = $this->insertDivergence($userId);

        $session = $this->connectedSession($userId, true);
        $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'submitted']);

        $divergence = db_connect()->table('profile_divergences')->where('id', $divId)->get()->getRowArray();
        $this->assertNotNull($divergence['resolved_at'], 'Divergence must be marked resolved after submission');
    }

    public function testResolvingWithSubmittedRedirectsToHome(): void
    {
        $email  = 'resolve-sub3@example.com';
        $userId = $this->insertUser($email);
        $this->insertDivergence($userId);

        $session = $this->connectedSession($userId, true);
        $result  = $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'submitted']);

        $result->assertRedirectTo(site_url('/'));
    }

    // ------------------------------------------------------------------
    // Resolution with 'keep' — AC1 (profile unchanged, divergences resolved)
    // ------------------------------------------------------------------

    public function testResolvingWithKeepLeavesProfileUnchanged(): void
    {
        $email  = 'resolve-keep@example.com';
        $userId = $this->insertUser($email, 'Alice', 'Martin', '0611111111');
        $this->insertDivergence($userId, 'Alice', 'MARTIN', '0699999999');

        $session = $this->connectedSession($userId, true);
        $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'keep']);

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('Martin', $user['last_name'], 'Last name must not change when choice is keep');
        $this->assertSame('0611111111', $user['phone'], 'Phone must not change when choice is keep');
    }

    public function testResolvingWithKeepMarksDivergencesResolved(): void
    {
        $email  = 'resolve-keep2@example.com';
        $userId = $this->insertUser($email);
        $divId  = $this->insertDivergence($userId);

        $session = $this->connectedSession($userId, true);
        $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'keep']);

        $divergence = db_connect()->table('profile_divergences')->where('id', $divId)->get()->getRowArray();
        $this->assertNotNull($divergence['resolved_at'], 'Divergence must be marked resolved even when choice is keep');
    }

    public function testResolvingWithKeepRedirectsToHome(): void
    {
        $email  = 'resolve-keep3@example.com';
        $userId = $this->insertUser($email);
        $this->insertDivergence($userId);

        $session = $this->connectedSession($userId, true);
        $result  = $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'keep']);

        $result->assertRedirectTo(site_url('/'));
    }

    public function testResolvingClearsPendingFlag(): void
    {
        $email  = 'flag-clear@example.com';
        $userId = $this->insertUser($email);
        $this->insertDivergence($userId);

        $session = $this->connectedSession($userId, true);
        $result  = $this->csrfPostWithSession('auth/profile-resolution', $session, ['choice' => 'keep']);

        $result->assertSessionMissing('pending_profile_resolution');
    }

    // ------------------------------------------------------------------
    // Bypass prevention — AC1 (GET / with pending flag → redirect to resolution)
    // ------------------------------------------------------------------

    public function testAccessingHomeWithPendingFlagRedirectsToResolution(): void
    {
        $email  = 'bypass@example.com';
        $userId = $this->insertUser($email);

        $result = $this->withSession($this->connectedSession($userId, true))->get('/');

        $result->assertRedirectTo(site_url('auth/profile-resolution'));
    }

    public function testAccessingHomeWithoutPendingFlagShowsConnectedHome(): void
    {
        $email  = 'nobypass@example.com';
        $userId = $this->insertUser($email);

        $db = db_connect();
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

        $result = $this->withSession($this->connectedSession($userId, false))->get('/');

        $result->assertStatus(200);
        $result->assertSee('Mes kermesses');
    }

    // ------------------------------------------------------------------
    // Show page — guard: no divergences → redirect home
    // ------------------------------------------------------------------

    public function testShowPageWithNoDivergencesRedirectsToHome(): void
    {
        $email  = 'noshow@example.com';
        $userId = $this->insertUser($email);

        $result = $this->withSession($this->connectedSession($userId, true))->get('auth/profile-resolution');

        $result->assertRedirectTo(site_url('/'));
    }

    public function testShowPageWithDivergencesDisplaysResolutionForm(): void
    {
        $email  = 'show@example.com';
        $userId = $this->insertUser($email, 'Alice', 'Martin');
        $this->insertDivergence($userId, 'Alice', 'MARTIN');

        $result = $this->withSession($this->connectedSession($userId, true))->get('auth/profile-resolution');

        $result->assertStatus(200);
        $result->assertSee('Martin');
        $result->assertSee('MARTIN');
        $result->assertSee('Garder mon profil actuel');
        $result->assertSee('Utiliser les informations soumises');
    }

    // ------------------------------------------------------------------
    // Resolution page: unauthenticated → redirect to login
    // ------------------------------------------------------------------

    public function testUnauthenticatedUserCannotAccessResolutionPage(): void
    {
        $result = $this->get('auth/profile-resolution');

        $result->assertRedirectTo(site_url('auth/login'));
    }
}
