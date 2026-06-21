<?php

use App\Services\StandDeletionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for StandDeletionService — Story 2.4.
 *
 * Exercises the soft-delete (deactivation) logic against a real SQLite test DB:
 * confirmation-mode resolution, the deactivation transaction, the anti-TOCTOU
 * confirmation_changed guard, and the batched strongConfirmationByStand helper.
 *
 * @internal
 */
final class StandDeletionServiceTest extends CIUnitTestCase
{
    private int $kermesseId = 1;
    private int $otherUser  = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('
            CREATE TABLE IF NOT EXISTS db_stands (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id   INTEGER NOT NULL,
                name          TEXT    NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                status        TEXT    NOT NULL DEFAULT "active",
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            CREATE TABLE IF NOT EXISTS db_slots (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                stand_id   INTEGER NOT NULL,
                starts_at  DATETIME NOT NULL,
                ends_at    DATETIME NOT NULL,
                capacity   INTEGER  NOT NULL DEFAULT 1,
                status     TEXT     NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            DROP TABLE IF EXISTS db_slot_signups;
            CREATE TABLE IF NOT EXISTS db_slot_signups (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id                   INTEGER  NOT NULL,
                user_id                   INTEGER  NULL,
                deleted_at                DATETIME NULL DEFAULT NULL,
                last_modified_by_user_id  INTEGER  NULL DEFAULT NULL,
                last_modified_at          DATETIME NULL DEFAULT NULL,
                first_name                TEXT     NULL DEFAULT NULL,
                last_name                 TEXT     NULL DEFAULT NULL,
                email                     TEXT     NULL DEFAULT NULL,
                phone                     TEXT     NULL DEFAULT NULL,
                admin_notes               TEXT     NULL DEFAULT NULL,
                created_by                INTEGER  NULL DEFAULT NULL,
                viewed_at                 DATETIME NULL DEFAULT NULL,
                accepted_at               DATETIME NULL DEFAULT NULL,
                rejected_at               DATETIME NULL DEFAULT NULL,
                canceled_at               DATETIME NULL DEFAULT NULL,
                canceled_by               INTEGER  NULL DEFAULT NULL,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_slot_signups');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeStand(string $name = 'Stand'): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $this->kermesseId,
            'name'          => $name,
            'display_order' => 1,
            'status'        => 'active',
        ]);
        return (int) $db->insertID();
    }

    private function makeSlot(int $standId): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => '2026-06-15 09:00:00',
            'ends_at'   => '2026-06-15 11:00:00',
            'capacity'  => 5,
            'status'    => 'active',
        ]);
        return (int) $db->insertID();
    }

    private function makeSignup(int $slotId, string $state = 'active'): void
    {
        $row = ['slot_id' => $slotId, 'user_id' => $this->otherUser];
        $row += match ($state) {
            'cancelled'            => ['canceled_at' => '2026-01-01 00:00:00', 'canceled_by' => $this->otherUser],
            'removed'              => ['canceled_at' => '2026-01-01 00:00:00', 'canceled_by' => 9999],
            'refused'              => ['rejected_at' => '2026-01-01 00:00:00'],
            'deactivated', 'deleted' => ['deleted_at' => '2026-01-01 00:00:00'],
            default                => [],
        };
        db_connect()->table('slot_signups')->insert($row);
    }

    private function standStatus(int $standId): ?string
    {
        return db_connect()
            ->query("SELECT status FROM db_stands WHERE id = {$standId}")
            ->getRowArray()['status'] ?? null;
    }

    // ------------------------------------------------------------------
    // confirmationModeFor / countActiveSignups
    // ------------------------------------------------------------------

    public function testSimpleModeWhenNoSignups(): void
    {
        $standId = $this->makeStand();
        $this->makeSlot($standId);

        $service = new StandDeletionService();
        $this->assertSame(StandDeletionService::CONFIRM_SIMPLE, $service->confirmationModeFor($standId));
    }

    public function testStrongModeWhenActiveSignupExists(): void
    {
        $standId = $this->makeStand();
        $slotId  = $this->makeSlot($standId);
        $this->makeSignup($slotId, 'active');

        $service = new StandDeletionService();
        $this->assertSame(StandDeletionService::CONFIRM_STRONG, $service->confirmationModeFor($standId));
    }

    public function testInactiveSignupsAreNotCounted(): void
    {
        $standId = $this->makeStand();
        $slotId  = $this->makeSlot($standId);
        $this->makeSignup($slotId, 'cancelled');
        $this->makeSignup($slotId, 'deactivated');
        $this->makeSignup($slotId, 'deleted');

        $service = new StandDeletionService();
        $this->assertSame(0, $service->countActiveSignups($standId));
        $this->assertSame(StandDeletionService::CONFIRM_SIMPLE, $service->confirmationModeFor($standId));
    }

    // ------------------------------------------------------------------
    // deactivate
    // ------------------------------------------------------------------

    public function testDeactivateSimpleSucceeds(): void
    {
        $standId = $this->makeStand();
        $this->makeSlot($standId);

        $service = new StandDeletionService();
        $result  = $service->deactivate($standId, $this->kermesseId, StandDeletionService::CONFIRM_SIMPLE);

        $this->assertSame(StandDeletionService::RESULT_SUCCESS, $result);
        $this->assertSame('deactivated', $this->standStatus($standId));
    }

    public function testDeactivateStrongRendersSignupsInactive(): void
    {
        $standId = $this->makeStand();
        $slotId  = $this->makeSlot($standId);
        $this->makeSignup($slotId, 'active');

        $service = new StandDeletionService();
        $result  = $service->deactivate($standId, $this->kermesseId, StandDeletionService::CONFIRM_STRONG);

        $this->assertSame(StandDeletionService::RESULT_SUCCESS, $result);
        $this->assertSame('deactivated', $this->standStatus($standId));
        $this->assertSame(0, $service->countActiveSignups($standId));
    }

    public function testDeactivateReturnsConfirmationChangedWhenStrongNeededButSimpleConfirmed(): void
    {
        // Signup arrived after the page was displayed: now strong confirm is required,
        // but the request only carried a simple confirmation → must be rejected, nothing changed.
        $standId = $this->makeStand();
        $slotId  = $this->makeSlot($standId);
        $this->makeSignup($slotId, 'active');

        $service = new StandDeletionService();
        $result  = $service->deactivate($standId, $this->kermesseId, StandDeletionService::CONFIRM_SIMPLE);

        $this->assertSame(StandDeletionService::RESULT_CONFIRMATION_CHANGED, $result);
        $this->assertSame('active', $this->standStatus($standId));
        $this->assertSame(1, $service->countActiveSignups($standId));
    }

    public function testDeactivateFailsForUnknownOrAlreadyDeactivatedStand(): void
    {
        $service = new StandDeletionService();
        $result  = $service->deactivate(999999, $this->kermesseId, StandDeletionService::CONFIRM_SIMPLE);

        $this->assertSame(StandDeletionService::RESULT_FAILED, $result);
    }

    // ------------------------------------------------------------------
    // strongConfirmationByStand (batch, N+1-free)
    // ------------------------------------------------------------------

    public function testStrongConfirmationByStandMapsOnlyStandsWithActiveSignups(): void
    {
        $withSignups = $this->makeStand('Avec inscrits');
        $slot        = $this->makeSlot($withSignups);
        $this->makeSignup($slot, 'active');

        $withoutSignups = $this->makeStand('Sans inscrits');
        $this->makeSlot($withoutSignups);

        $service = new StandDeletionService();
        $map     = $service->strongConfirmationByStand([$withSignups, $withoutSignups]);

        $this->assertTrue($map[$withSignups]);
        $this->assertFalse($map[$withoutSignups]);
    }

    public function testStrongConfirmationByStandHandlesEmptyInput(): void
    {
        $service = new StandDeletionService();
        $this->assertSame([], $service->strongConfirmationByStand([]));
    }
}
