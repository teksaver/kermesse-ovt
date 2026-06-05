<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for stand management — Story 2.2.
 *
 * POST /admin/kermesses/{id}/stands        — create stand
 * POST /admin/kermesses/{id}/stands/{sid}  — update stand
 *
 * @internal
 */
final class AdminStandTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_owners');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertActiveOwnerAndKermesse(string $slug = 'stand-test-kermesse'): array
    {
        $db    = db_connect();
        $email = "owner-{$slug}@example.com";
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Stand Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, '{$slug}', 'Kermesse Stand Test', '2026-09-01', 'Paris', 'Test', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = (int) $db->insertID();

        return ['ownerId' => $ownerId, 'kermesseId' => $kermesseId];
    }

    private function insertStand(int $kermesseId, string $name, int $order = 0): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_stands (kermesse_id, name, display_order, status, created_at, updated_at)
            VALUES ({$kermesseId}, '" . addslashes($name) . "', {$order}, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function authorizedSession(int $ownerId, int $kermesseId): array
    {
        return [
            'owner_admin_authenticated' => true,
            'owner_id'                  => $ownerId,
            'kermesse_id'               => $kermesseId,
        ];
    }

    // ------------------------------------------------------------------
    // CREATE — valid stand
    // ------------------------------------------------------------------

    public function testCreateValidStandRedirectsToDashboard(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse();

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => 'Pêche à la ligne',
            ]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Expected redirect after valid stand creation'
        );

        $location = $result->response()->getHeaderLine('Location') ?? '';
        $this->assertStringContainsString("admin/kermesses/{$ids['kermesseId']}", $location,
            'Should redirect to dashboard');
    }

    public function testCreateValidStandPersistsAndAppearsInDashboard(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-persist');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => 'Buvette',
            ]);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $result->assertOK();
        $this->assertStringContainsString('Buvette', $result->response()->getBody());
    }

    // ------------------------------------------------------------------
    // UPDATE — valid stand
    // ------------------------------------------------------------------

    public function testUpdateValidStandPersistsNewName(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-update');
        $standId = $this->insertStand($ids['kermesseId'], 'Ancien nom');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}", [
                csrf_token() => csrf_hash(),
                'name'       => 'Nouveau nom',
            ]);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $this->assertStringContainsString('Nouveau nom', $result->response()->getBody());
    }

    public function testUpdateOnlyModifiesTargetStand(): void
    {
        $ids      = $this->insertActiveOwnerAndKermesse('stand-isolation');
        $standId1 = $this->insertStand($ids['kermesseId'], 'Stand A');
        $standId2 = $this->insertStand($ids['kermesseId'], 'Stand B');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId1}", [
                csrf_token() => csrf_hash(),
                'name'       => 'Stand A modifié',
            ]);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Stand A modifié', $body);
        $this->assertStringContainsString('Stand B', $body);
    }

    // ------------------------------------------------------------------
    // VALIDATION — invalid form
    // ------------------------------------------------------------------

    public function testCreateWithEmptyNameShowsError(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-empty');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => '',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Indiquez le nom du stand', $body,
            'Empty name must show French error message');
    }

    public function testCreateWithArrayNameShowsErrorAndDoesNotPersist(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-array-name');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => ['Buvette'],
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Indiquez le nom du stand', $body);
        $this->assertStringNotContainsString('value="Array"', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_stands WHERE kermesse_id = {$ids['kermesseId']}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithUnicodeBlankNameShowsErrorAndDoesNotPersist(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-unicode-blank');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => "\u{00A0}\u{200B}\u{202F}",
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Indiquez le nom du stand', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_stands WHERE kermesse_id = {$ids['kermesseId']}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithTooLongNameShowsError(): void
    {
        $ids    = $this->insertActiveOwnerAndKermesse('stand-toolong');
        $tooLong = str_repeat('A', 256);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => $tooLong,
            ]);

        $statusCode = $result->response()->getStatusCode();
        $body       = $result->response()->getBody();
        $this->assertFalse(
            in_array($statusCode, [301, 302, 303, 307, 308], true),
            'Too-long name must not redirect'
        );
        $this->assertStringNotContainsString('SELECT', $body);
    }

    public function testCreateInvalidFormPreservesEnteredValue(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-preserve');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => '   ',
            ]);

        $body = $result->response()->getBody();
        // Form should re-render with input element for stand name
        $this->assertStringContainsString('name="name"', $body,
            'Form should redisplay after invalid submission');
    }

    public function testUpdateInvalidFormDoesNotOverwriteExistingName(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-no-overwrite');
        $standId = $this->insertStand($ids['kermesseId'], 'Nom original');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}", [
                csrf_token() => csrf_hash(),
                'name'       => '',
            ]);

        $db    = db_connect();
        $stand = $db->query("SELECT name FROM db_stands WHERE id = {$standId}")->getRowArray();
        $this->assertSame('Nom original', $stand['name'],
            'Invalid update must not overwrite existing name in DB');
    }

    // ------------------------------------------------------------------
    // AUTHORIZATION — cross-kermesse boundary
    // ------------------------------------------------------------------

    public function testCreateStandOnAnotherKermesseDenied(): void
    {
        $ids1 = $this->insertActiveOwnerAndKermesse('stand-auth-1');
        $ids2 = $this->insertActiveOwnerAndKermesse('stand-auth-2');

        // Owner 1 tries to post to kermesse 2
        $result = $this->withSession($this->authorizedSession($ids1['ownerId'], $ids1['kermesseId']))
            ->post("admin/kermesses/{$ids2['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => 'Tentative',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            "Cross-kermesse stand creation should be denied (got {$statusCode})"
        );

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_stands WHERE kermesse_id = {$ids2['kermesseId']}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt'],
            'No stand must be created on another owner\'s kermesse');
    }

    public function testUpdateStandOnAnotherKermesseDenied(): void
    {
        $ids1    = $this->insertActiveOwnerAndKermesse('stand-auth-upd-1');
        $ids2    = $this->insertActiveOwnerAndKermesse('stand-auth-upd-2');
        $standId = $this->insertStand($ids2['kermesseId'], 'Stand cible');

        // Owner 1 session but URL points to kermesse 2 / stand belonging to kermesse 2
        $result = $this->withSession($this->authorizedSession($ids1['ownerId'], $ids1['kermesseId']))
            ->post("admin/kermesses/{$ids2['kermesseId']}/stands/{$standId}", [
                csrf_token() => csrf_hash(),
                'name'       => 'Hack',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            "Cross-kermesse stand update should be denied (got {$statusCode})"
        );

        $db    = db_connect();
        $stand = $db->query("SELECT name FROM db_stands WHERE id = {$standId}")->getRowArray();
        $this->assertSame('Stand cible', $stand['name'],
            'Stand name must not be changed when update is denied');
    }

    public function testCreateWithPendingOwnerShowsValidationRequired(): void
    {
        $db    = db_connect();
        $email = 'stand-pending@example.com';
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Pending Stand Owner', 'owner_pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, 'stand-pending', 'Kermesse Pending Stand', '2026-09-01', 'Paris', 'Test', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = (int) $db->insertID();

        $result = $this->withSession($this->authorizedSession($ownerId, $kermesseId))
            ->post("admin/kermesses/{$kermesseId}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => 'Buvette',
            ]);

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertStringContainsString('validation', strtolower($result->response()->getBody()));
    }

    // ------------------------------------------------------------------
    // EMPTY SLOT STATE — stand without slot
    // ------------------------------------------------------------------

    public function testStandWithoutSlotShowsEmptySlotState(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-no-slot');
        $this->insertStand($ids['kermesseId'], 'Maquillage');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Aucun créneau pour le moment', $body);
        $this->assertStringContainsString('Ajouter un créneau', $body);
    }

    public function testStandWithoutSlotDoesNotExposeSlotPostRoute(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-no-slot-leak');
        $this->insertStand($ids['kermesseId'], 'Tombola');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('/admin/slots', $body);
        $this->assertStringNotContainsString('action="/admin/kermesses/' . $ids['kermesseId'] . '/slots"', $body);
    }

    // ------------------------------------------------------------------
    // NO TECHNICAL LEAK
    // ------------------------------------------------------------------

    public function testResponseDoesNotLeakTechnicalDetails(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-no-leak');
        $this->insertStand($ids['kermesseId'], 'Buvette');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('Exception', $body);
    }

    public function testCreateResponseDoesNotLeakOnError(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-leak-error');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => '',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
    }

    // ------------------------------------------------------------------
    // DASHBOARD — add stand form visible when no stands
    // ------------------------------------------------------------------

    public function testEmptyKermesseDashboardShowsAddStandForm(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-form-visible');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Nom du stand', $body,
            'Add stand form must be visible even when no stands exist');
        $this->assertStringContainsString('Ajouter le stand', $body);
    }

    public function testDashboardShowsStandFlashSuccess(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-flash-success');

        $result = $this->withSession([
            ...$this->authorizedSession($ids['ownerId'], $ids['kermesseId']),
            'flash_success' => 'Stand ajouté.',
            '__ci_vars'     => ['flash_success' => 'old'],
        ])->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Stand ajouté.', $body);
        $this->assertStringContainsString('role="status"', $body);
    }

    // ------------------------------------------------------------------
    // DUPLICATE — same name case-insensitive
    // ------------------------------------------------------------------

    public function testCreateDuplicateNameShowsError(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('stand-dup');
        $this->insertStand($ids['kermesseId'], 'Buvette');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands", [
                csrf_token() => csrf_hash(),
                'name'       => 'buvette',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Un stand porte déjà ce nom', $body);
    }
}
