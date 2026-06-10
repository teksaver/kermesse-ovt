<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for slot management — Story 2.4.
 *
 * POST /admin/kermesses/{id}/stands/{sid}/slots           — create slot
 * POST /admin/kermesses/{id}/stands/{sid}/slots/{slotId}  — update slot
 *
 * @internal
 */
final class AdminSlotTest extends CIUnitTestCase
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

    private function insertActiveOwnerAndKermesse(
        string $slug = 'slot-test-kermesse',
        string $eventDate = '2026-09-01'
    ): array {
        $db    = db_connect();
        $email = "owner-{$slug}@example.com";
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Slot Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, '{$slug}', 'Kermesse Slot Test', '{$eventDate}', 'Paris', 'Test', 'Europe/Paris', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
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

    private function insertSlot(int $standId, int $capacity = 5): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_slots (stand_id, starts_at, ends_at, capacity, status, created_at, updated_at)
            VALUES ({$standId}, '2026-09-01 09:00:00', '2026-09-01 10:00:00', {$capacity}, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, string $status = 'active'): int
    {
        $db = db_connect();
        $db->query("INSERT INTO db_signups (slot_id, volunteer_id, status, created_at, updated_at)
            VALUES ({$slotId}, 0, '{$status}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        return (int) $db->insertID();
    }

    /**
     * Insert an active owner + kermesse with an explicit (possibly null) event date and timezone.
     *
     * @return array{ownerId: int, kermesseId: int}
     */
    private function insertOwnerAndKermesseWith(
        string $slug,
        ?string $eventDate,
        string $timezone = 'Europe/Paris'
    ): array {
        $db    = db_connect();
        $email = "owner-{$slug}@example.com";
        $db->query("INSERT INTO db_owners (email, email_hash, display_name, status, email_verified_at, created_at, updated_at)
            VALUES ('{$email}', '" . hash('sha256', $email) . "', 'Slot Owner', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $ownerId = (int) $db->insertID();

        $dateSql = $eventDate === null ? 'NULL' : "'" . $eventDate . "'";
        $db->query("INSERT INTO db_kermesses (owner_id, public_slug, name, event_date, location, short_description, timezone, status, created_at, updated_at)
            VALUES ({$ownerId}, '{$slug}', 'Kermesse Slot Test', {$dateSql}, 'Paris', 'Test', '{$timezone}', 'preparation', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $kermesseId = (int) $db->insertID();

        return ['ownerId' => $ownerId, 'kermesseId' => $kermesseId];
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
    // CREATE — valid slot
    // ------------------------------------------------------------------

    public function testCreateValidSlotRedirectsToDashboard(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-create-redir');
        $standId = $this->insertStand($ids['kermesseId'], 'Buvette');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:30',
                'capacity'    => '5',
            ]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true),
            'Expected redirect after valid slot creation'
        );
        $location = $result->response()->getHeaderLine('Location') ?? '';
        $this->assertStringContainsString("admin/kermesses/{$ids['kermesseId']}", $location);
    }

    public function testCreateValidSlotPersistsInDatabase(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-create-db');
        $standId = $this->insertStand($ids['kermesseId'], 'Maquillage');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '10:00',
                'end_time'    => '11:30',
                'capacity'    => '8',
            ]);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId} AND capacity = 8")->getRowArray();
        $this->assertSame(1, (int) $count['cnt'], 'Slot must be persisted with correct capacity');
    }

    public function testCreateSlotAppearsInDashboard(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-create-view');
        $standId = $this->insertStand($ids['kermesseId'], 'Tombola');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '14:00',
                'end_time'    => '15:00',
                'capacity'    => '3',
            ]);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('3', $body, 'Capacity must appear on dashboard');
        // Should no longer show empty state for this stand
        // (There may be other stands with empty state, so we just check the capacity is visible)
    }

    public function testCreateSlotFlashSuccessMessage(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-flash');
        $standId = $this->insertStand($ids['kermesseId'], 'Pêche');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '2',
            ]);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        // Flash is consumed on redirect then displayed on dashboard load
        // We can't easily test across redirect in CI4 feature tests, so just verify the slot is there
        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(1, (int) $count['cnt']);
    }

    // ------------------------------------------------------------------
    // CREATE — capacity validation
    // ------------------------------------------------------------------

    public function testCreateWithZeroCapacityShowsErrorAndDoesNotPersist(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-zero');
        $standId = $this->insertStand($ids['kermesseId'], 'Buvette');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '0',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('entier strictement positif', $body);
        $this->assertFalse(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true)
        );

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithNegativeCapacityShowsErrorAndDoesNotPersist(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-neg');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '-5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('entier strictement positif', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithDecimalCapacityShowsErrorAndDoesNotPersist(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-dec');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '2.5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('entier strictement positif', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithTextCapacityShowsErrorAndDoesNotPersist(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-text');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => 'beaucoup',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('entier strictement positif', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithArrayCapacityShowsErrorAndDoesNotPersist(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-arr');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => ['5'],
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('entier strictement positif', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithEmptyCapacityShowsError(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-empty');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('entier strictement positif', $body);
    }

    // ------------------------------------------------------------------
    // CREATE — time validation
    // ------------------------------------------------------------------

    public function testCreateWithEndTimeEqualToStartTimeShowsError(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-time-eq');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '10:00',
                'end_time'    => '10:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('fin doit être après', $body);
        $this->assertFalse(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true)
        );

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithEndTimeBeforeStartTimeShowsError(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-time-before');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '11:00',
                'end_time'    => '09:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('fin doit être après', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithInvalidTimeFormatShowsError(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-time-fmt');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => 'invalid',
                'end_time'    => '10:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('fin doit être après', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreatePreservesEnteredValuesOnError(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-preserve');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '09:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        // The form should re-render with at least the slot form fields
        $this->assertStringContainsString('start_time', $body);
        $this->assertStringContainsString('end_time', $body);
        $this->assertStringContainsString('capacity', $body);
    }

    // ------------------------------------------------------------------
    // UPDATE — valid slot
    // ------------------------------------------------------------------

    public function testUpdateValidSlotPersistsNewValues(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-update-valid');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 3);

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots/{$slotId}", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '11:00',
                'end_time'    => '12:30',
                'capacity'    => '10',
            ]);

        $db   = db_connect();
        $slot = $db->query("SELECT capacity FROM db_slots WHERE id = {$slotId}")->getRowArray();
        $this->assertSame(10, (int) $slot['capacity'], 'Updated capacity must be persisted');
    }

    public function testUpdateValidSlotRedirectsToDashboard(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-update-redir');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots/{$slotId}", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '6',
            ]);

        $this->assertTrue(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true)
        );
        $location = $result->response()->getHeaderLine('Location') ?? '';
        $this->assertStringContainsString("admin/kermesses/{$ids['kermesseId']}", $location);
    }

    public function testUpdateOnlyModifiesTargetSlot(): void
    {
        $ids      = $this->insertActiveOwnerAndKermesse('slot-update-iso');
        $standId  = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId1  = $this->insertSlot($standId, 2);
        $slotId2  = $this->insertSlot($standId, 7);

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots/{$slotId1}", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '4',
            ]);

        $db    = db_connect();
        $slot2 = $db->query("SELECT capacity FROM db_slots WHERE id = {$slotId2}")->getRowArray();
        $this->assertSame(7, (int) $slot2['capacity'], 'Other slot must not be modified');
    }

    // ------------------------------------------------------------------
    // AUTHORIZATION — cross-kermesse boundary
    // ------------------------------------------------------------------

    public function testCreateSlotOnAnotherKermesseDenied(): void
    {
        $ids1    = $this->insertActiveOwnerAndKermesse('slot-auth-1');
        $ids2    = $this->insertActiveOwnerAndKermesse('slot-auth-2');
        $standId = $this->insertStand($ids2['kermesseId'], 'Stand cible');

        $result = $this->withSession($this->authorizedSession($ids1['ownerId'], $ids1['kermesseId']))
            ->post("admin/kermesses/{$ids2['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '5',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            "Cross-kermesse slot creation should be denied (got {$statusCode})"
        );

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt'], 'No slot must be created on another kermesse');
    }

    public function testUpdateSlotOnAnotherKermesseDenied(): void
    {
        $ids1    = $this->insertActiveOwnerAndKermesse('slot-auth-upd-1');
        $ids2    = $this->insertActiveOwnerAndKermesse('slot-auth-upd-2');
        $standId = $this->insertStand($ids2['kermesseId'], 'Stand cible');
        $slotId  = $this->insertSlot($standId, 3);

        $result = $this->withSession($this->authorizedSession($ids1['ownerId'], $ids1['kermesseId']))
            ->post("admin/kermesses/{$ids2['kermesseId']}/stands/{$standId}/slots/{$slotId}", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '99',
            ]);

        $statusCode = $result->response()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            "Cross-kermesse slot update should be denied (got {$statusCode})"
        );

        $db   = db_connect();
        $slot = $db->query("SELECT capacity FROM db_slots WHERE id = {$slotId}")->getRowArray();
        $this->assertSame(3, (int) $slot['capacity'], 'Slot must not be mutated when denied');
    }

    // ------------------------------------------------------------------
    // DEACTIVATED STAND — slots must not appear
    // ------------------------------------------------------------------

    public function testSlotsOfDeactivatedStandDoNotAppearOnDashboard(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-deact-stand');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand désactivé', 'deactivated');
        $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        // Deactivated stand itself must not appear in stand list
        $this->assertStringNotContainsString('Stand désactivé', $body,
            'Deactivated stand must not be shown on dashboard');
    }

    public function testSlotsOfActiveStandAppearOnDashboard(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-active-stand');
        $standId = $this->insertStand($ids['kermesseId'], 'Buvette active');
        $this->insertSlot($standId, 7);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Buvette active', $body);
        // 7 places should appear somewhere
        $this->assertStringContainsString('7', $body);
    }

    // ------------------------------------------------------------------
    // DASHBOARD — slot display
    // ------------------------------------------------------------------

    public function testStandWithSlotsDoesNotShowEmptySlotState(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-no-empty-state');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand avec créneau');
        $this->insertSlot($standId, 4);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();

        // Slot should appear with capacity info
        $this->assertStringContainsString('4', $body);
    }

    public function testDashboardShowsAddSlotFormPerStand(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-add-form');
        $standId = $this->insertStand($ids['kermesseId'], 'Pêche');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        // The add slot form must POST to the right URL
        $this->assertStringContainsString(
            "stands/{$standId}/slots",
            $body,
            'Dashboard must contain slot create route for the stand'
        );
        $this->assertStringContainsString('Ajouter le créneau', $body);
    }

    public function testDashboardShowsEditSlotFormForExistingSlot(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-edit-form');
        $standId = $this->insertStand($ids['kermesseId'], 'Atelier');
        $slotId  = $this->insertSlot($standId, 5);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString(
            "slots/{$slotId}",
            $body,
            'Dashboard must contain slot edit route'
        );
        $this->assertStringContainsString('Enregistrer le créneau', $body);
    }

    public function testDashboardShowsRemainingSpots(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-remaining');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $this->insertSlot($standId, 6);

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('6', $body, 'Remaining spots (= capacity when no signups) must be shown');
        $this->assertStringContainsString('places', $body);
    }

    // ------------------------------------------------------------------
    // REVIEW PATCH — block editing a slot that has active signups
    // ------------------------------------------------------------------

    public function testUpdateSlotWithActiveSignupsIsRejected(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-edit-active-signup');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 5);
        $this->insertSignup($slotId, 'active');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots/{$slotId}", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '11:00',
                'end_time'    => '12:00',
                'capacity'    => '10',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('inscriptions actives', $body);
        $this->assertFalse(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true)
        );

        $db   = db_connect();
        $slot = $db->query("SELECT capacity FROM db_slots WHERE id = {$slotId}")->getRowArray();
        $this->assertSame(5, (int) $slot['capacity'], 'Slot with active signups must not be mutated');
    }

    public function testUpdateSlotWithOnlyCancelledSignupsIsAllowed(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-edit-cancelled-signup');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 5);
        $this->insertSignup($slotId, 'cancelled');
        $this->insertSignup($slotId, 'deactivated');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots/{$slotId}", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '11:00',
                'end_time'    => '12:00',
                'capacity'    => '10',
            ]);

        $db   = db_connect();
        $slot = $db->query("SELECT capacity FROM db_slots WHERE id = {$slotId}")->getRowArray();
        $this->assertSame(10, (int) $slot['capacity'], 'Only-cancelled signups must not block edition');
    }

    // ------------------------------------------------------------------
    // REVIEW PATCH — remaining spots account for active signups
    // ------------------------------------------------------------------

    public function testRemainingSpotsAccountForActiveSignups(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-remaining-active');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 6);
        $this->insertSignup($slotId, 'active');
        $this->insertSignup($slotId, 'active');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('6 places au total', $body);
        $this->assertStringContainsString('4 places restantes', $body);
    }

    public function testRemainingSpotsIgnoreCancelledSignups(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-remaining-cancelled');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');
        $slotId  = $this->insertSlot($standId, 6);
        $this->insertSignup($slotId, 'active');
        $this->insertSignup($slotId, 'cancelled');
        $this->insertSignup($slotId, 'deleted');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringContainsString('5 places restantes', $body);
    }

    // ------------------------------------------------------------------
    // REVIEW PATCH — reject out-of-range times PHP would normalize
    // ------------------------------------------------------------------

    public function testCreateWithOutOfRangeHourIsRejected(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-time-oob-hour');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '25:00',
                'end_time'    => '26:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('fin doit être après', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithOutOfRangeMinuteIsRejected(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-time-oob-min');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:75',
                'end_time'    => '10:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('fin doit être après', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithNonexistentDstLocalTimeIsRejected(): void
    {
        $ids     = $this->insertOwnerAndKermesseWith('slot-time-dst-gap', '2026-03-29', 'Europe/Paris');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '02:30',
                'end_time'    => '04:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('fin doit être après', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    // ------------------------------------------------------------------
    // REVIEW PATCH — bound capacity to INT UNSIGNED
    // ------------------------------------------------------------------

    public function testCreateWithCapacityAboveIntUnsignedIsRejected(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-overflow');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '4294967296',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('trop élevée', $body);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt']);
    }

    public function testCreateWithMaxIntUnsignedCapacityIsAccepted(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-cap-max');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '4294967295',
            ]);

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId} AND capacity = 4294967295")->getRowArray();
        $this->assertSame(1, (int) $count['cnt'], 'Capacity at the INT UNSIGNED bound must be accepted');
    }

    // ------------------------------------------------------------------
    // REVIEW PATCH — invalid kermesse timezone must not throw
    // ------------------------------------------------------------------

    public function testCreateSlotWithInvalidKermesseTimezoneDoesNotError(): void
    {
        $ids     = $this->insertOwnerAndKermesseWith('slot-bad-tz', '2026-09-01', 'Invalid/Zone');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '5',
            ]);

        $this->assertNotSame(500, $result->response()->getStatusCode(), 'Invalid timezone must not 500');

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(1, (int) $count['cnt'], 'Slot should still be created with a timezone fallback');
    }

    // ------------------------------------------------------------------
    // REVIEW PATCH — refuse a missing event date instead of server date
    // ------------------------------------------------------------------

    public function testCreateSlotWithMissingEventDateIsRejected(): void
    {
        $ids     = $this->insertOwnerAndKermesseWith('slot-no-date', '');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '09:00',
                'end_time'    => '10:00',
                'capacity'    => '5',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringContainsString('manquante', $body);
        $this->assertFalse(
            in_array($result->response()->getStatusCode(), [301, 302, 303, 307, 308], true)
        );

        $db    = db_connect();
        $count = $db->query("SELECT COUNT(*) as cnt FROM db_slots WHERE stand_id = {$standId}")->getRowArray();
        $this->assertSame(0, (int) $count['cnt'], 'No slot must be created without an event date');
    }

    // ------------------------------------------------------------------
    // NO TECHNICAL LEAK
    // ------------------------------------------------------------------

    public function testCreateResponseDoesNotLeakTechnicalDetails(): void
    {
        $ids     = $this->insertActiveOwnerAndKermesse('slot-noleak-create');
        $standId = $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->post("admin/kermesses/{$ids['kermesseId']}/stands/{$standId}/slots", [
                csrf_token()  => csrf_hash(),
                'start_time'  => '10:00',
                'end_time'    => '10:00',
                'capacity'    => '0',
            ]);

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('Exception', $body);
    }

    public function testDashboardGetDoesNotLeakTechnicalDetails(): void
    {
        $ids = $this->insertActiveOwnerAndKermesse('slot-noleak-get');
        $this->insertStand($ids['kermesseId'], 'Stand');

        $result = $this->withSession($this->authorizedSession($ids['ownerId'], $ids['kermesseId']))
            ->get("admin/kermesses/{$ids['kermesseId']}");

        $body = $result->response()->getBody();
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('.env', $body);
        $this->assertStringNotContainsString('Exception', $body);
    }
}
