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
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stand_id INTEGER NOT NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                capacity INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_signups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id INTEGER NOT NULL,
                volunteer_name TEXT NOT NULL DEFAULT \'\',
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
        $db->query('DELETE FROM db_slots');
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

    private function insertStand(int $kermesseId, string $name, int $order = 0, string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_stands (kermesse_id, name, display_order, status, created_at, updated_at)
            VALUES ({$kermesseId}, '" . addslashes($name) . "', {$order}, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSlot(int $standId): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_slots (stand_id, starts_at, ends_at, capacity, status, created_at, updated_at)
            VALUES ({$standId}, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 5, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, string $status = 'active', ?string $deletedAt = null): int
    {
        $db = db_connect();
        $deletedAtSql = $deletedAt === null ? 'NULL' : "'" . addslashes($deletedAt) . "'";
        $db->query("INSERT INTO db_signups (slot_id, volunteer_name, status, deleted_at, created_at, updated_at)
            VALUES ({$slotId}, 'Bénévole Test', '{$status}', {$deletedAtSql}, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
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

    // ------------------------------------------------------------------
    // DELETE — simple confirmation (no active signups)
    // ------------------------------------------------------------------

    public function testDeleteStandWithoutSignupsSucceedsAndRedirects(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-simple');
        $standId = $this->insertStand($ids['kermesseId'], 'Buvette à supprimer');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Expected redirect after successful deletion'
        );
        $location = $result->response()->getHeaderLine('Location') ?? '';
        $this->assertStringContainsString("admin/kermesses/{$ids['kermesseId']}", $location);
    }

    public function testDeleteStandWithoutSignupsDeactivatesInDatabase(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-db');
        $standId = $this->insertStand($ids['kermesseId'], 'Pêche');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $db    = db_connect();
        $stand = $db->query("SELECT status FROM db_stands WHERE id = {$standId}")->getRowArray();
        $this->assertSame('deactivated', $stand['status'], 'Stand must be logically deactivated');
    }

    public function testDeleteStandDisappearsFromDashboardAfterDeletion(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-dashboard');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand à effacer');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $this->assertStringNotContainsString('Stand à effacer', $result->response()->getBody());
    }

    public function testDeleteStandMissingSimpleConfirmShowsError(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-no-confirm');
        $standId = $this->insertStand($ids['kermesseId'], 'Tombola');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token() => csrf_hash(),
            ]);

        $statusCode = $result->response()->getStatusCode();
        $body       = $result->response()->getBody();
        $this->assertFalse(
            in_array($statusCode, [301, 302, 303, 307, 308], true),
            'Missing simple confirmation must not redirect'
        );
        $this->assertStringContainsString('Confirmez la suppression du stand', $body);
    }

    public function testDeleteStandMissingSimpleConfirmStandStillActive(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-no-confirm-db');
        $standId = $this->insertStand($ids['kermesseId'], 'Maquillage');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token() => csrf_hash(),
            ]);

        $db    = db_connect();
        $stand = $db->query("SELECT status FROM db_stands WHERE id = {$standId}")->getRowArray();
        $this->assertSame('active', $stand['status'], 'Stand must remain active when confirmation is missing');
    }

    // ------------------------------------------------------------------
    // DELETE — authorization boundary
    // ------------------------------------------------------------------

    public function testDeleteStandOnAnotherKermesseDenied(): void
    {
        $ids1    = $this->insertActiveOwnerAndKermesse('stand-del-auth-1');
        $ids2    = $this->insertActiveOwnerAndKermesse('stand-del-auth-2');
        $standId = $this->insertStand($ids2['kermesseId'], 'Stand protégé');

        $result = $this->withSession($this->authorizedSession($ids1['ownerId'], $ids1['kermesseId']))
            ->post("admin/kermesses/{$ids2['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            "Cross-kermesse delete should be denied (got {$statusCode})"
        );

        $db    = db_connect();
        $stand = $db->query("SELECT status FROM db_stands WHERE id = {$standId}")->getRowArray();
        $this->assertSame('active', $stand['status'], 'Cross-kermesse stand must not be deactivated');
    }

    public function testDeleteAlreadyDeactivatedStandDenied(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-already');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand déjà inactif', 0, 'deactivated');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            'Deleting an already-deactivated stand must be denied'
        );
    }

    // ------------------------------------------------------------------
    // DELETE — strong confirmation (with active signups)
    // ------------------------------------------------------------------

    public function testDeleteWithSignupsWithSupprimerDeactivatesStandAndSignups(): void
    {
        $ids      = $this->insertActiveOwnerAndKermesse('stand-del-strong');
        $standId  = $this->insertStand($ids['kermesseId'], 'Stand avec inscrits');
        $slotId   = $this->insertSlot($standId);
        $signupId = $this->insertSignup($slotId);

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()   => csrf_hash(),
                'confirm_word' => 'SUPPRIMER',
            ]);

        $db     = db_connect();
        $stand  = $db->query("SELECT status FROM db_stands WHERE id = {$standId}")->getRowArray();
        $signup = $db->query("SELECT status FROM db_signups WHERE id = {$signupId}")->getRowArray();
        $this->assertSame('deactivated', $stand['status'], 'Stand must be deactivated');
        $this->assertSame('deactivated', $signup['status'], 'Signup must be deactivated');
    }

    public function testDeleteWithSignupsWithoutSupprimerRejected(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-strong-reject');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand bloqué');
        $slotId  = $this->insertSlot($standId);
        $signupId = $this->insertSignup($slotId);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()   => csrf_hash(),
                'confirm_word' => 'supprimer',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $body       = $result->response()->getBody();
        $this->assertFalse(
            in_array($statusCode, [301, 302, 303, 307, 308], true),
            'Wrong confirm word must not redirect'
        );
        $this->assertStringContainsString('SUPPRIMER', $body, 'Error message must reference SUPPRIMER');

        $db    = db_connect();
        $stand = $db->query("SELECT status FROM db_stands WHERE id = {$standId}")->getRowArray();
        $signup = $db->query("SELECT status FROM db_signups WHERE id = {$signupId}")->getRowArray();
        $this->assertSame('active', $stand['status'], 'Stand must remain active when strong confirm fails');
        $this->assertSame('active', $signup['status'], 'Signup must remain active when strong confirm fails');
    }

    public function testDeletionServiceRejectsSimpleModeWhenActiveSignupExists(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-race-guard');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand race guard');
        $slotId  = $this->insertSlot($standId);
        $signupId = $this->insertSignup($slotId);

        $service = new \App\Services\StandDeletionService();
        $result  = $service->deactivate($standId, $ids['kermesseId'], \App\Services\StandDeletionService::CONFIRM_SIMPLE);

        $db     = db_connect();
        $stand  = $db->query("SELECT status FROM db_stands WHERE id = {$standId}")->getRowArray();
        $signup = $db->query("SELECT status FROM db_signups WHERE id = {$signupId}")->getRowArray();
        $this->assertSame(\App\Services\StandDeletionService::RESULT_CONFIRMATION_CHANGED, $result);
        $this->assertSame('active', $stand['status'], 'Simple mode must not deactivate a stand that now has signups');
        $this->assertSame('active', $signup['status'], 'Simple mode must not deactivate active signups');
    }

    public function testDeleteWithSignupsDoesNotAffectOtherStandsSignups(): void
    {
        $ids      = $this->insertActiveOwnerAndKermesse('stand-del-isolation');
        $standId1 = $this->insertStand($ids['kermesseId'], 'Stand cible');
        $standId2 = $this->insertStand($ids['kermesseId'], 'Stand voisin');
        $slot1    = $this->insertSlot($standId1);
        $slot2    = $this->insertSlot($standId2);
        $signup1  = $this->insertSignup($slot1);
        $signup2  = $this->insertSignup($slot2);

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId1}/delete", [
                csrf_token()   => csrf_hash(),
                'confirm_word' => 'SUPPRIMER',
            ]);

        $db      = db_connect();
        $signup2Row = $db->query("SELECT status FROM db_signups WHERE id = {$signup2}")->getRowArray();
        $this->assertSame('active', $signup2Row['status'], 'Other stand signups must not be affected');
    }

    public function testDeleteCancelledSignupsAreIgnoredInCount(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-cancelled');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand annulés');
        $slotId  = $this->insertSlot($standId);
        $this->insertSignup($slotId, 'cancelled');
        $this->insertSignup($slotId, 'deactivated');

        // Only cancelled/deactivated signups → simple confirmation should suffice
        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Cancelled/deactivated signups only should allow simple confirmation'
        );
    }

    public function testDeleteSoftDeletedSignupsAreIgnoredInCountAndMutation(): void
    {
        $ids      = $this->insertActiveOwnerAndKermesse('stand-del-soft-deleted');
        $standId  = $this->insertStand($ids['kermesseId'], 'Stand supprimé logiquement');
        $slotId   = $this->insertSlot($standId);
        $signupId = $this->insertSignup($slotId, 'active', '2026-06-05 10:00:00');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token()     => csrf_hash(),
                'confirm_delete' => '1',
            ]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Soft-deleted signups should not require strong confirmation'
        );

        $db     = db_connect();
        $signup = $db->query("SELECT status FROM db_signups WHERE id = {$signupId}")->getRowArray();
        $this->assertSame('active', $signup['status'], 'Soft-deleted signups must not be mutated again');
    }

    // ------------------------------------------------------------------
    // DELETE — dashboard shows delete form
    // ------------------------------------------------------------------

    public function testDashboardStandHasDeleteForm(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-form');
        $standId = $this->insertStand($ids['kermesseId'], 'Atelier');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('stands/' . $standId . '/delete', $body,
            'Dashboard must contain a delete route for the stand');
        $this->assertStringContainsString('Supprimer le stand', $body,
            'Dashboard must contain a delete button label');
    }

    public function testDashboardStrongDeleteWorksWithoutHtmlDisabledButton(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-btn-disabled');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand inscrit');
        $slotId  = $this->insertSlot($standId);
        $this->insertSignup($slotId);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('stand-delete-form--strong', $body,
            'Dashboard must render the strong confirmation mode from the view model');
        $this->assertDoesNotMatchRegularExpression('/id="stand-delete-btn-' . $standId . '"[^>]*disabled/s', $body,
            'Strong confirmation submit must remain available without JavaScript');
        $this->assertStringContainsString('data-confirm-word', $body,
            'Strong confirm input must have JS contract attribute');
    }

    // ------------------------------------------------------------------
    // DELETE — no technical leak
    // ------------------------------------------------------------------

    public function testDeleteResponseDoesNotLeakTechnicalDetails(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('stand-del-noleak');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand propre');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/delete", [
                csrf_token() => csrf_hash(),
                // Missing confirm — triggers error render
            ]);

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
    }
}
