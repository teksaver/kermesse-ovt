<?php

declare(strict_types=1);

use App\Models\SignupModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Story 4.2 — Tests unitaires de SignupModel::findActiveForUserAndKermesse().
 *
 * Invariant d'affichage de « Mes participations » : seules les inscriptions
 * ACTIVES d'un utilisateur, sur une kermesse donnée, remontent — jointes au stand
 * et au créneau (nom du stand, début, fin). Les statuts cancelled/deactivated/deleted
 * et les lignes soft-deleted (deleted_at) sont exclus, exactement comme
 * countActiveBySlotIds() : « Mes participations » et la disponibilité publique ne
 * doivent jamais diverger (UX-DR23).
 *
 * @internal
 */
final class SignupModelTest extends CIUnitTestCase
{
    private int $userId          = 0;
    private int $otherUserId     = 0;
    private int $kermesseId      = 0;
    private int $otherKermesseId = 0;
    private int $standId         = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTables();
        $this->insertBaseFixtures();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->query('DELETE FROM db_signups');
        $db->query('DELETE FROM db_kermesse_user_roles');
        $db->query('DELETE FROM db_slots');
        $db->query('DELETE FROM db_stands');
        $db->query('DELETE FROM db_kermesses');
        $db->query('DELETE FROM db_users');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testReturnsActiveSignupJoinedWithStandAndSlot(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame('Stand Buvette', $rows[0]['stand_name']);
        $this->assertSame('2026-10-10 09:00:00', $rows[0]['starts_at']);
        $this->assertSame('2026-10-10 12:00:00', $rows[0]['ends_at']);
    }

    public function testExcludesCancelledDeactivatedDeletedRemovedAndSoftDeleted(): void
    {
        $active      = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $cancelled   = $this->insertSlot($this->standId, '2026-10-10 10:00:00', '2026-10-10 11:00:00');
        $deactivated = $this->insertSlot($this->standId, '2026-10-10 11:00:00', '2026-10-10 12:00:00');
        $deleted     = $this->insertSlot($this->standId, '2026-10-10 12:00:00', '2026-10-10 13:00:00');
        $softDeleted = $this->insertSlot($this->standId, '2026-10-10 13:00:00', '2026-10-10 14:00:00');
        $removed     = $this->insertSlot($this->standId, '2026-10-10 14:00:00', '2026-10-10 15:00:00');

        $this->insertSignup($active, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($cancelled, $this->userId, SignupModel::STATUS_CANCELLED);
        $this->insertSignup($deactivated, $this->userId, SignupModel::STATUS_DEACTIVATED);
        $this->insertSignup($deleted, $this->userId, SignupModel::STATUS_DELETED);
        $this->insertSignup($removed, $this->userId, SignupModel::STATUS_REMOVED);
        // Statut 'active' mais soft-deleted : doit aussi être exclu (deleted_at IS NULL).
        $this->insertSignup($softDeleted, $this->userId, SignupModel::STATUS_ACTIVE, '2026-01-01 00:00:00');

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-10-10 09:00:00', $rows[0]['starts_at']);
    }

    public function testScopedToUserAndKermesse(): void
    {
        $slotKermesse = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');

        // Inscription active d'un AUTRE utilisateur sur la même kermesse.
        $this->insertSignup($slotKermesse, $this->otherUserId, SignupModel::STATUS_ACTIVE);

        // Inscription active de l'utilisateur, mais sur une AUTRE kermesse.
        $otherStandId = $this->insertStand($this->otherKermesseId, 'Stand Ailleurs');
        $otherSlot    = $this->insertSlot($otherStandId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $this->insertSignup($otherSlot, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertSame([], $rows);
    }

    public function testOrdersChronologicallyByStart(): void
    {
        $late  = $this->insertSlot($this->standId, '2026-10-10 14:00:00', '2026-10-10 16:00:00');
        $early = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');

        // Insérées dans le désordre : le tri doit être chronologique.
        $this->insertSignup($late, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($early, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-10 09:00:00', $rows[0]['starts_at']);
        $this->assertSame('2026-10-10 14:00:00', $rows[1]['starts_at']);
    }

    public function testReturnsEmptyArrayWhenNoActiveSignups(): void
    {
        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertSame([], $rows);
    }

    public function testReturnsActiveSignupMatchedByEmailWhenUserIdIsZeroForStateless(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');

        // On simule une inscription stateless publique (user_id = 0)
        db_connect()->table('signups')->insert([
            'slot_id'    => $slotId,
            'user_id'    => 0,
            'email'      => 'benevole@signupmodel.test', // Correspond à l'email de $this->userId
            'status'     => SignupModel::STATUS_ACTIVE,
            'deleted_at' => null,
        ]);

        $rows = model(SignupModel::class)->findActiveForUserAndKermesse($this->userId, $this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame('Stand Buvette', $rows[0]['stand_name']);
    }


    // ------------------------------------------------------------------
    // Story 4.3 — findActiveOwnedInKermesse() : garde ownership + scope kermesse
    // ------------------------------------------------------------------

    public function testFindActiveOwnedInKermesseScopesToOwnerKermesseAndActive(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $model = model(SignupModel::class);

        // Propriétaire + bonne kermesse + actif → trouvé.
        $row = $model->findActiveOwnedInKermesse($signupId, $this->userId, $this->kermesseId);
        $this->assertNotNull($row);
        $this->assertSame($signupId, (int) $row['id']);

        // Mauvais utilisateur → null (ownership).
        $this->assertNull($model->findActiveOwnedInKermesse($signupId, $this->otherUserId, $this->kermesseId));

        // Mauvaise kermesse → null (scope, lié via slot→stand).
        $this->assertNull($model->findActiveOwnedInKermesse($signupId, $this->userId, $this->otherKermesseId));
    }

    public function testFindActiveOwnedInKermesseExcludesCancelled(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_CANCELLED);

        $this->assertNull(
            model(SignupModel::class)->findActiveOwnedInKermesse($signupId, $this->userId, $this->kermesseId)
        );
    }

    // ------------------------------------------------------------------
    // Story 4.3 — markCancelled() : libère la place, garde ownership
    // ------------------------------------------------------------------

    public function testMarkCancelledFreesTheSlotForOwnerOnly(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $model = model(SignupModel::class);

        // Mauvais propriétaire → no-op : la place reste occupée.
        $this->assertFalse($model->markCancelled($signupId, $this->otherUserId));
        $this->assertSame(1, $model->countActiveBySlotIds([$slotId])[$slotId] ?? 0);

        // Bon propriétaire → annulé : la place est libérée instantanément
        // (même définition « actif » que la disponibilité publique — UX-DR23).
        $this->assertTrue($model->markCancelled($signupId, $this->userId));
        $this->assertSame(0, $model->countActiveBySlotIds([$slotId])[$slotId] ?? 0);
    }

    public function testMarkCancelledIsIdempotentOnAlreadyCancelled(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_CANCELLED);

        // Already cancelled → no active row to transition → false (no double-free).
        $this->assertFalse(model(SignupModel::class)->markCancelled($signupId, $this->userId));
    }

    // ------------------------------------------------------------------
    // Story 4.4 — findActiveParticipantsForKermesse() : récapitulatif nominatif
    // ------------------------------------------------------------------

    public function testFindActiveParticipantsReturnsVolunteerIdentityAndContact(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->setPhone($this->userId, '0612345678');

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame($slotId, (int) $rows[0]['slot_id']);
        $this->assertSame($this->standId, (int) $rows[0]['stand_id']);
        $this->assertSame('Benevole', $rows[0]['first_name']);
        $this->assertSame('Test', $rows[0]['last_name']);
        $this->assertSame('benevole@signupmodel.test', $rows[0]['email']);
        $this->assertSame('0612345678', $rows[0]['phone']);
    }

    public function testFindActiveParticipantsExcludesInactiveAndSoftDeleted(): void
    {
        $active      = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $cancelled   = $this->insertSlot($this->standId, '2026-10-10 10:00:00', '2026-10-10 11:00:00');
        $deactivated = $this->insertSlot($this->standId, '2026-10-10 11:00:00', '2026-10-10 12:00:00');
        $deleted     = $this->insertSlot($this->standId, '2026-10-10 12:00:00', '2026-10-10 13:00:00');
        $removed     = $this->insertSlot($this->standId, '2026-10-10 13:00:00', '2026-10-10 14:00:00');
        $softDeleted = $this->insertSlot($this->standId, '2026-10-10 14:00:00', '2026-10-10 15:00:00');

        $this->insertSignup($active, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($cancelled, $this->userId, SignupModel::STATUS_CANCELLED);
        $this->insertSignup($deactivated, $this->userId, SignupModel::STATUS_DEACTIVATED);
        $this->insertSignup($deleted, $this->userId, SignupModel::STATUS_DELETED);
        $this->insertSignup($removed, $this->userId, SignupModel::STATUS_REMOVED);
        $this->insertSignup($softDeleted, $this->userId, SignupModel::STATUS_ACTIVE, '2026-01-01 00:00:00');

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);

        // Même définition « actif » que la disponibilité publique : seule l'inscription
        // active non soft-deleted remonte (cohérence places occupées/restantes).
        $this->assertCount(1, $rows);
        $this->assertSame($active, (int) $rows[0]['slot_id']);
    }

    public function testFindActiveParticipantsScopedToKermesse(): void
    {
        $slotHere = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $this->insertSignup($slotHere, $this->userId, SignupModel::STATUS_ACTIVE);

        // Inscription active dans une AUTRE kermesse : ne doit pas fuiter ici.
        $otherStandId = $this->insertStand($this->otherKermesseId, 'Stand Ailleurs');
        $otherSlot    = $this->insertSlot($otherStandId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $this->insertSignup($otherSlot, $this->otherUserId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame($slotHere, (int) $rows[0]['slot_id']);
    }

    public function testFindActiveParticipantsListsEveryVolunteerOnASlot(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($slotId, $this->otherUserId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($slotId, (int) $row['slot_id']);
        }
    }

    // ------------------------------------------------------------------
    // Story 5.3 — findActiveParticipantsForKermesse() expose les infos modificateur
    // ------------------------------------------------------------------

    public function testFindActiveParticipantsIncludesModifierNameWhenSet(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        db_connect()->table('signups')->where('id', $signupId)->update([
            'last_modified_by_user_id' => $this->otherUserId,
            'last_modified_at'         => '2026-10-11 10:00:00',
        ]);

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame('Autre', $rows[0]['modifier_first_name']);
        $this->assertSame('2026-10-11 10:00:00', $rows[0]['last_modified_at']);
    }

    public function testFindActiveParticipantsHasNullModifierWhenNotModified(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['modifier_first_name']);
        $this->assertNull($rows[0]['last_modified_at']);
    }

    // ------------------------------------------------------------------
    // Story 5.1 — Colonnes de traçage des modifications admin
    // ------------------------------------------------------------------

    public function testStampAdminModificationSetsFieldsAndReturnsTrue(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $result = model(SignupModel::class)->stampAdminModification($signupId, $this->otherUserId);

        $this->assertTrue($result);

        $row = db_connect()->table('signups')->where('id', $signupId)->get()->getRowArray();
        $this->assertSame($this->otherUserId, (int) $row['last_modified_by_user_id']);
        $this->assertNotNull($row['last_modified_at']);
    }

    public function testStampAdminModificationReturnsFalseForUnknownId(): void
    {
        $result = model(SignupModel::class)->stampAdminModification(99999, $this->userId);

        $this->assertFalse($result);
    }

    public function testModificationTrackingFieldsAreNullByDefault(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $row = db_connect()->table('signups')->where('slot_id', $slotId)->get()->getRowArray();

        $this->assertNull($row['last_modified_by_user_id']);
        $this->assertNull($row['last_modified_at']);
    }

    public function testAllowsWritingModificationTrackingFields(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');

        model(SignupModel::class)->skipValidation(true)->insert([
            'slot_id'                  => $slotId,
            'user_id'                  => $this->userId,
            'status'                   => SignupModel::STATUS_ACTIVE,
            'last_modified_by_user_id' => $this->userId,
            'last_modified_at'         => '2026-10-11 10:00:00',
        ]);

        $row = db_connect()->table('signups')->where('slot_id', $slotId)->get()->getRowArray();
        $this->assertSame($this->userId, (int) $row['last_modified_by_user_id']);
        $this->assertSame('2026-10-11 10:00:00', $row['last_modified_at']);
    }

    // ------------------------------------------------------------------
    // Story 5.10 — markCancelledByAdmin() : statut removed (distinct de cancelled)
    // ------------------------------------------------------------------

    public function testMarkCancelledByAdminSetsRemovedStatus(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $model = model(SignupModel::class);

        $this->assertTrue($model->markCancelledByAdmin($signupId, $this->otherUserId));

        $row = db_connect()->table('signups')->where('id', $signupId)->get()->getRowArray();
        $this->assertSame(SignupModel::STATUS_REMOVED, $row['status']);
    }

    public function testMarkCancelledByAdminFreesSlotCapacity(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_ACTIVE);

        $model = model(SignupModel::class);
        $this->assertSame(1, $model->countActiveBySlotIds([$slotId])[$slotId] ?? 0);

        $model->markCancelledByAdmin($signupId, $this->otherUserId);
        $this->assertSame(0, $model->countActiveBySlotIds([$slotId])[$slotId] ?? 0);
    }

    public function testMarkCancelledByAdminIsIdempotentOnAlreadyRemoved(): void
    {
        $slotId   = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $signupId = $this->insertSignupReturningId($slotId, $this->userId, SignupModel::STATUS_REMOVED);

        $this->assertFalse(model(SignupModel::class)->markCancelledByAdmin($signupId, $this->otherUserId));
    }

    public function testFindActiveParticipantsExcludesRemovedStatus(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 12:00:00');
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_REMOVED);

        $rows = model(SignupModel::class)->findActiveParticipantsForKermesse($this->kermesseId);
        $this->assertSame([], $rows);
    }

    // ------------------------------------------------------------------
    // Story 5.10 — findHistoricalParticipantsForKermesse()
    // ------------------------------------------------------------------

    public function testHistoricalQueryReturnsCancelledAndRemovedSignups(): void
    {
        $slot1 = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $slot2 = $this->insertSlot($this->standId, '2026-10-10 10:00:00', '2026-10-10 11:00:00');

        $this->insertSignup($slot1, $this->userId, SignupModel::STATUS_CANCELLED);
        $this->insertSignup($slot2, $this->otherUserId, SignupModel::STATUS_REMOVED);

        $rows = model(SignupModel::class)->findHistoricalParticipantsForKermesse($this->kermesseId);

        $this->assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        $this->assertContains('cancelled', $statuses);
        $this->assertContains('removed', $statuses);
    }

    public function testHistoricalQueryExcludesActiveAndSystemStatuses(): void
    {
        $slot1 = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $slot2 = $this->insertSlot($this->standId, '2026-10-10 10:00:00', '2026-10-10 11:00:00');
        $slot3 = $this->insertSlot($this->standId, '2026-10-10 11:00:00', '2026-10-10 12:00:00');

        $this->insertSignup($slot1, $this->userId, SignupModel::STATUS_ACTIVE);
        $this->insertSignup($slot2, $this->userId, SignupModel::STATUS_DEACTIVATED);
        $this->insertSignup($slot3, $this->otherUserId, SignupModel::STATUS_DELETED);

        $rows = model(SignupModel::class)->findHistoricalParticipantsForKermesse($this->kermesseId);

        $this->assertSame([], $rows);
    }

    public function testHistoricalQueryScopedToKermesse(): void
    {
        $slotHere = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $this->insertSignup($slotHere, $this->userId, SignupModel::STATUS_CANCELLED);

        $otherStandId = $this->insertStand($this->otherKermesseId, 'Stand Autre');
        $otherSlot    = $this->insertSlot($otherStandId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        $this->insertSignup($otherSlot, $this->otherUserId, SignupModel::STATUS_REMOVED);

        $rows = model(SignupModel::class)->findHistoricalParticipantsForKermesse($this->kermesseId);

        $this->assertCount(1, $rows);
        $this->assertSame($slotHere, (int) $rows[0]['slot_id']);
    }

    public function testHistoricalQueryExcludesSoftDeleted(): void
    {
        $slotId = $this->insertSlot($this->standId, '2026-10-10 09:00:00', '2026-10-10 10:00:00');
        // status = cancelled but soft-deleted — must not appear.
        $this->insertSignup($slotId, $this->userId, SignupModel::STATUS_CANCELLED, '2026-01-01 00:00:00');

        $rows = model(SignupModel::class)->findHistoricalParticipantsForKermesse($this->kermesseId);
        $this->assertSame([], $rows);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function setPhone(int $userId, string $phone): void
    {
        db_connect()->table('users')->where('id', $userId)->update(['phone' => $phone]);
    }

    private function insertSignupReturningId(int $slotId, int $userId, string $status): int
    {
        $db = db_connect();
        $db->table('signups')->insert([
            'slot_id'    => $slotId,
            'user_id'    => $userId,
            'status'     => $status,
            'deleted_at' => null,
        ]);

        return (int) $db->insertID();
    }

    private function insertBaseFixtures(): void
    {
        $db = db_connect();

        foreach ([
            ['benevole@signupmodel.test', 'Benevole', 'Test'],
            ['autre@signupmodel.test', 'Autre', 'Test'],
        ] as [$email, $first, $last]) {
            $db->table('users')->insert([
                'email'      => $email,
                'email_hash' => hash('sha256', $email),
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => '',
            ]);
        }

        $rows               = $db->query('SELECT id FROM db_users ORDER BY id ASC')->getResultArray();
        $this->userId       = (int) $rows[0]['id'];
        $this->otherUserId  = (int) $rows[1]['id'];

        $db->table('kermesses')->insert([
            'created_by'  => $this->userId,
            'public_slug' => 'signupmodel-k1',
            'name'        => 'Kermesse 4.2',
            'status'      => 'open',
        ]);
        $this->kermesseId = (int) $db->insertID();

        $db->table('kermesses')->insert([
            'created_by'  => $this->userId,
            'public_slug' => 'signupmodel-k2',
            'name'        => 'Kermesse Ailleurs',
            'status'      => 'open',
        ]);
        $this->otherKermesseId = (int) $db->insertID();

        $this->standId = $this->insertStand($this->kermesseId, 'Stand Buvette');
    }

    private function insertStand(int $kermesseId, string $name): int
    {
        $db = db_connect();
        $db->table('stands')->insert([
            'kermesse_id'   => $kermesseId,
            'name'          => $name,
            'display_order' => 1,
            'status'        => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSlot(int $standId, string $startsAt, string $endsAt): int
    {
        $db = db_connect();
        $db->table('slots')->insert([
            'stand_id'  => $standId,
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'capacity'  => 5,
            'status'    => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function insertSignup(int $slotId, int $userId, string $status, ?string $deletedAt = null): void
    {
        db_connect()->table('signups')->insert([
            'slot_id'    => $slotId,
            'user_id'    => $userId,
            'status'     => $status,
            'deleted_at' => $deletedAt,
        ]);
    }

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
                capacity   INTEGER  NOT NULL,
                status     TEXT     NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->query('
            DROP TABLE IF EXISTS db_signups;
            CREATE TABLE db_signups (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                slot_id                   INTEGER  NOT NULL,
                user_id                   INTEGER  NOT NULL,
                status                    TEXT     NOT NULL DEFAULT "active",
                deleted_at                DATETIME NULL DEFAULT NULL,
                last_modified_by_user_id  INTEGER  NULL DEFAULT NULL,
                last_modified_at          DATETIME NULL DEFAULT NULL,
                first_name                TEXT     NULL DEFAULT NULL,
                last_name                 TEXT     NULL DEFAULT NULL,
                email                     TEXT     NULL DEFAULT NULL,
                phone                     TEXT     NULL DEFAULT NULL,
                admin_notes               TEXT     NULL DEFAULT NULL,
                created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        // findActiveParticipantsForKermesse LEFT JOINs kermesse_user_roles (Story 5.10).
        $db->query('
            CREATE TABLE IF NOT EXISTS db_kermesse_user_roles (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                kermesse_id     INTEGER NOT NULL,
                user_id         INTEGER NOT NULL,
                role            TEXT    NOT NULL,
                invited_by      INTEGER,
                invited_at      DATETIME NULL DEFAULT NULL,
                accepted_at     DATETIME NULL DEFAULT NULL,
                first_access_at DATETIME NULL DEFAULT NULL,
                last_access_at  DATETIME NULL DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }
}
