<?php

use App\Services\KermesseLifecycleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for kermesse lifecycle (open/close) and admin preview — Story 2.5.
 *
 * POST /admin/kermesses/{id}/open     — open signups (blocked unless publishable)
 * POST /admin/kermesses/{id}/close    — close signups
 * GET  /admin/kermesses/{id}/preview  — read-only admin preview of the planning
 *
 * @internal
 */
final class AdminLifecycleTest extends CIUnitTestCase
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
                event_date TEXT,
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
            CREATE TABLE IF NOT EXISTS db_signups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id INTEGER NOT NULL,
                volunteer_id INTEGER NOT NULL DEFAULT 0,
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

    private function insertOwnerAndKermesse(string $slug, string $status = 'preparation'): array
    {
        $db    = db_connect();
        $email = "owner-{$slug}@example.com";
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, '{$slug}', 'Kermesse {$slug}', '2026-09-01', 'Paris', 'Fête de fin d''année', 'Europe/Paris', '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = (int) $db->insertID();

        return ['ownerId' => $ownerId, 'kermesseId' => $kermesseId];
    }

    private function insertStand(int $kermesseId, string $name, string $status = 'active'): int
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
            VALUES ({$standId}, '2026-09-01 09:00:00', '2026-09-01 10:00:00', {$capacity}, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_signups (slot_id, volunteer_id, status, created_at, updated_at)
            VALUES ({$slotId}, 0, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
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

    private function statusOf(int $kermesseId): string
    {
        $db  = db_connect();
        $row = $db->query("SELECT status FROM db_kermesses WHERE id = {$kermesseId}")->getRowArray();
        return (string) $row['status'];
    }

    // ------------------------------------------------------------------
    // AC1 — opening blocked until publishable
    // ------------------------------------------------------------------

    public function testOpenBlockedWithoutAnyStand(): void
    {
        $ids = $this->insertOwnerAndKermesse('open-no-stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString(
            'Ajoutez au moins un stand avec un créneau avant d&#039;ouvrir les inscriptions.',
            $body
        );
        $this->assertFalse(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Blocked open must not redirect'
        );
        $this->assertSame('preparation', $this->statusOf($ids['kermesseId']), 'Status must stay preparation');
    }

    public function testOpenBlockedWithStandButNoSlot(): void
    {
        $ids = $this->insertOwnerAndKermesse('open-no-slot');
        $this->insertStand($ids['kermesseId'], 'Buvette');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $this->assertStringContainsString(
            'Ajoutez au moins un stand avec un créneau avant d&#039;ouvrir les inscriptions.',
            $result->response()->getBody()
        );
        $this->assertSame('preparation', $this->statusOf($ids['kermesseId']));
    }

    public function testOpenBlockedWhenOnlyDeactivatedStandsAndSlots(): void
    {
        $ids = $this->insertOwnerAndKermesse('open-deactivated');
        // Deactivated stand with an active slot — must not count.
        $deadStand = $this->insertStand($ids['kermesseId'], 'Stand mort', 'deactivated');
        $this->insertSlot($deadStand, 5, 'active');
        // Active stand with only a deactivated slot — must not count.
        $liveStand = $this->insertStand($ids['kermesseId'], 'Stand vivant', 'active');
        $this->insertSlot($liveStand, 5, 'deactivated');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $this->assertStringContainsString(
            'Ajoutez au moins un stand avec un créneau avant d&#039;ouvrir les inscriptions.',
            $result->response()->getBody()
        );
        $this->assertSame('preparation', $this->statusOf($ids['kermesseId']));
    }

    // ------------------------------------------------------------------
    // AC2 — opening succeeds when publishable
    // ------------------------------------------------------------------

    public function testOpenSucceedsWithActiveStandAndSlot(): void
    {
        $ids     = $this->insertOwnerAndKermesse('open-ok');
        $standId = $this->insertStand($ids['kermesseId'], 'Pêche');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Successful open must redirect'
        );
        $location = $result->response()->getHeaderLine('Location') ?? '';
        $this->assertStringContainsString("admin/kermesses/{$ids['kermesseId']}", $location);
        $this->assertSame('open', $this->statusOf($ids['kermesseId']));
    }

    public function testDashboardShowsOpenBadgeAfterOpening(): void
    {
        $ids     = $this->insertOwnerAndKermesse('open-badge', 'open');
        $standId = $this->insertStand($ids['kermesseId'], 'Tombola');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $this->assertStringContainsString('Inscriptions ouvertes', $result->response()->getBody());
    }

    // ------------------------------------------------------------------
    // AC3 — closing
    // ------------------------------------------------------------------

    public function testCloseFromOpenSetsClosedStatus(): void
    {
        $ids     = $this->insertOwnerAndKermesse('close-ok', 'open');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/close", [csrf_token() => csrf_hash()]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Successful close must redirect'
        );
        $this->assertSame('closed', $this->statusOf($ids['kermesseId']));
    }

    public function testCloseBlockedWhenKermesseIsStillInPreparation(): void
    {
        $ids     = $this->insertOwnerAndKermesse('close-preparation', 'preparation');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/close", [csrf_token() => csrf_hash()]);

        $this->assertSame(409, $result->response()->getStatusCode());
        $this->assertStringContainsString(
            'Les inscriptions ne peuvent être fermées que lorsqu&#039;elles sont ouvertes.',
            $result->response()->getBody()
        );
        $this->assertSame('preparation', $this->statusOf($ids['kermesseId']));
    }

    public function testDashboardShowsClosedBadgeAfterClosing(): void
    {
        $ids     = $this->insertOwnerAndKermesse('close-badge', 'closed');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $this->assertStringContainsString('Inscriptions fermées', $result->response()->getBody());
    }

    public function testClosedKermesseCanReopenWhenPublishable(): void
    {
        $ids     = $this->insertOwnerAndKermesse('reopen', 'closed');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 5);

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $this->assertSame('open', $this->statusOf($ids['kermesseId']));
    }

    // ------------------------------------------------------------------
    // Authorization — cross-owner boundary
    // ------------------------------------------------------------------

    public function testOwnerCannotOpenAnotherKermesse(): void
    {
        $a = $this->insertOwnerAndKermesse('auth-open-a');
        $b = $this->insertOwnerAndKermesse('auth-open-b');
        $standId = $this->insertStand($b['kermesseId'], 'Stand B');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($a['ownerId'], $a['kermesseId']))
            ->post("admin/kermesses/{$b['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $this->assertTrue(in_array($result->response()->getStatusCode(), [302, 403], true));
        $this->assertSame('preparation', $this->statusOf($b['kermesseId']), 'Other kermesse must stay untouched');
    }

    public function testOwnerCannotCloseAnotherKermesse(): void
    {
        $a = $this->insertOwnerAndKermesse('auth-close-a');
        $b = $this->insertOwnerAndKermesse('auth-close-b', 'open');

        $result = $this->withSession($this->authorizedSession($a['ownerId'], $a['kermesseId']))
            ->post("admin/kermesses/{$b['kermesseId']}/close", [csrf_token() => csrf_hash()]);

        $this->assertTrue(in_array($result->response()->getStatusCode(), [302, 403], true));
        $this->assertSame('open', $this->statusOf($b['kermesseId']), 'Other kermesse must stay untouched');
    }

    public function testOwnerCannotPreviewAnotherKermesse(): void
    {
        $a = $this->insertOwnerAndKermesse('auth-prev-a');
        $b = $this->insertOwnerAndKermesse('auth-prev-b');

        $result = $this->withSession($this->authorizedSession($a['ownerId'], $a['kermesseId']))
            ->get("admin/kermesses/{$b['kermesseId']}/preview");

        $this->assertTrue(in_array($result->response()->getStatusCode(), [302, 403], true));
    }

    public function testLifecycleServiceDoesNotReportSuccessWhenOwnerScopedUpdateAffectsNoRows(): void
    {
        $ids     = $this->insertOwnerAndKermesse('service-owner-scope');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 5);

        $result = (new KermesseLifecycleService())->open($ids['kermesseId'], $ids['ownerId'] + 999);

        $this->assertNotSame(KermesseLifecycleService::RESULT_SUCCESS, $result);
        $this->assertSame('preparation', $this->statusOf($ids['kermesseId']));
    }

    // ------------------------------------------------------------------
    // AC4 — admin preview (read-only)
    // ------------------------------------------------------------------

    public function testPreviewShowsStandsAndSlots(): void
    {
        $ids     = $this->insertOwnerAndKermesse('preview-content');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand Crêpes');
        $this->insertSlot($standId, 8);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}/preview");

        $body = $result->response()->getBody();
        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringContainsString('Stand Crêpes', $body);
        $this->assertStringContainsString('09:00', $body);
        $this->assertStringContainsString('places restantes', $body);
    }

    public function testPreviewIsReadOnlyAndPrivacySafe(): void
    {
        $ids     = $this->insertOwnerAndKermesse('preview-readonly');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 5);
        $this->insertSignup($slotId, 'active');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}/preview");

        $body = $result->response()->getBody();
        // No volunteer signup form / POST action on the preview.
        $this->assertStringNotContainsString('method="post"', $body, 'Preview must be read-only');
        // No volunteer personal data leaked.
        $this->assertStringNotContainsString('Jean Bénévole', $body);
        $this->assertStringNotContainsString('volunteer_name', $body);
        // No raw token or technical leak.
        $this->assertStringNotContainsString('token', strtolower($body));
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('Exception', $body);
    }

    public function testPreviewWorksForOpenAndClosedKermesse(): void
    {
        foreach (['preparation', 'open', 'closed'] as $i => $status) {
            $ids     = $this->insertOwnerAndKermesse("preview-status-{$i}", $status);
            $standId = $this->insertStand($ids['kermesseId'], 'Stand');
            $this->insertSlot($standId, 5);

            $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
                ->get("admin/kermesses/{$ids['kermesseId']}/preview");

            $this->assertSame(200, $result->response()->getStatusCode(), "Preview must work for {$status}");
        }
    }

    public function testPreviewCountsActiveSignupsForRemainingSpots(): void
    {
        $ids     = $this->insertOwnerAndKermesse('preview-remaining');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 6);
        $this->insertSignup($slotId, 'active');
        $this->insertSignup($slotId, 'cancelled');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}/preview");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('5 places restantes', $body, 'Cancelled signups must not reduce remaining spots');
    }

    // ------------------------------------------------------------------
    // AC5 — public link + copy affordance on the dashboard
    // ------------------------------------------------------------------

    public function testDashboardShowsPublicLinkAndCopyAttributes(): void
    {
        $ids = $this->insertOwnerAndKermesse('copy-link');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString(site_url('k/copy-link'), $body, 'Public volunteer link must be visible');
        $this->assertStringContainsString('data-copy-button', $body, 'Copy button hook must be present');
        $this->assertStringContainsString('Copier le lien', $body);
        $this->assertStringContainsString('Prévisualiser', $body);
    }

    public function testClipboardScriptProvidesManualFallbackFeedback(): void
    {
        $script = file_get_contents(ROOTPATH . 'public/assets/js/app.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('Copiez le lien sélectionné manuellement.', $script);
    }

    public function testDashboardOpenActionAppearsOnlyWhenPublishable(): void
    {
        $ids     = $this->insertOwnerAndKermesse('open-action-gate');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString("admin/kermesses/{$ids['kermesseId']}/open", $body, 'Open POST form must be present when publishable');
        $this->assertStringContainsString('Ouvrir les inscriptions', $body);
    }

    public function testDashboardOpenActionDisabledWhenNotPublishable(): void
    {
        $ids = $this->insertOwnerAndKermesse('open-action-disabled');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        // No usable open POST form when not publishable.
        $this->assertStringNotContainsString("admin/kermesses/{$ids['kermesseId']}/open", $body);
        $this->assertStringContainsString('Ajoutez au moins un stand avec un créneau avant d&#039;ouvrir les inscriptions.', $body);
    }

    // ------------------------------------------------------------------
    // No technical leak on lifecycle endpoints
    // ------------------------------------------------------------------

    public function testBlockedOpenDoesNotLeakTechnicalDetails(): void
    {
        $ids = $this->insertOwnerAndKermesse('open-noleak');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/open", [csrf_token() => csrf_hash()]);

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('Exception', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
    }
}
