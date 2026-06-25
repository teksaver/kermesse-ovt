<?php

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use App\Models\KermesseModel;

use App\Models\SlotModel;
use App\Models\StandModel;
use App\Services\EmailDeliveryResult;
use App\Services\EmailService;
use App\Services\IssuedToken;
use App\Services\SlotSignupService;
use App\Services\SlotSignupResult;
use App\Services\TokenService;
use App\Models\UserModel;
use App\Models\SlotSignupModel;

/**
 * Unit tests for SlotSignupService — Stories 3.2, 3.3 & 3.4.
 *
 * All DB access is mocked — including the transaction connection — so tests are
 * fast and fully isolated from any real database.
 * Story 3.3: user create/reuse, email normalization, insert-race fallback.
 * Story 3.4: kermesse open, slot state/capacity, duplicate, overlap constraints.
 *
 * @internal
 */
final class SlotSignupServiceTest extends CIUnitTestCase
{
    private function buildService(
        ?UserModel               $userModel              = null,
        ?SlotSignupModel         $slotSignupModel        = null,
        ?KermesseModel           $kermesseModel          = null,
        ?SlotModel               $slotModel              = null,
        ?BaseConnection          $db                     = null,
        ?EmailService            $emailService           = null,
        ?StandModel              $standModel             = null,
        ?TokenService            $tokenService           = null,
    ): SlotSignupService {
        return new SlotSignupService(
            $userModel              ?? $this->buildMockUserModel(),
            $slotSignupModel        ?? $this->buildMockSlotSignupModel(),
            $kermesseModel          ?? $this->buildMockKermesseModel(),
            $slotModel              ?? $this->buildMockSlotModel(),
            $db                     ?? $this->buildMockConnection(),
            $emailService           ?? $this->buildMockEmailService(),
            $standModel             ?? $this->buildMockStandModel(),
            $tokenService           ?? $this->buildMockTokenService(),
        );
    }

    private function buildMockTokenService(): TokenService
    {
        $mock = $this->createMock(TokenService::class);
        $mock->method('issueMagicLink')
            ->willReturn(new IssuedToken('default-test-token', 1));
        return $mock;
    }


    private function buildMockEmailService(bool $sent = true): EmailService
    {
        $mock = $this->createMock(EmailService::class);
        $mock->method('sendSignupConfirmationEmail')
            ->willReturn(new EmailDeliveryResult($sent, $sent ? null : 'No SMTP in tests'));
        return $mock;
    }

    private function buildMockStandModel(string $name = 'Buvette'): StandModel
    {
        $mock = $this->getMockBuilder(StandModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $mock->method('find')->willReturn(['id' => 5, 'name' => $name, 'status' => 'active']);
        return $mock;
    }

    /**
     * Transaction connection mock: begin/commit succeed so the service reaches the
     * invariant checks; no real database is ever touched.
     */
    private function buildMockConnection(): BaseConnection
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->method('ignore')->willReturnSelf();
        $builder->method('insert')->willReturn(true);

        $mock = $this->createMock(BaseConnection::class);
        $mock->method('transBegin')->willReturn(true);
        $mock->method('transCommit')->willReturn(true);
        $mock->method('table')->willReturn($builder);
        return $mock;
    }

    private function buildMockUserModel(int $returnedId = 42): UserModel
    {
        $mock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $mock->method('findByEmailHash')->willReturn(null);
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn($returnedId);
        return $mock;
    }

    /**
     * Build a SlotSignupModel mock with insert preconfigured.
     * countActiveForSlot/findActiveByEmailOrUserAndSlot/findOverlappingActiveByEmailOrUser
     * are left unconfigured so callers can override without FIFO stub conflicts.
     * Unconfigured mock methods return null — which the service treats as "no issue" (0
     * active signups, no duplicate, no overlap) for happy-path tests.
     */
    private function buildMockSlotSignupModel(int $returnedId = 99): SlotSignupModel
    {
        $mock = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert', 'countActiveForSlot', 'findActiveByEmailOrUserAndSlot', 'findOverlappingActiveByEmailOrUser'])
            ->getMock();
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn($returnedId);
        return $mock;
    }

    private function buildMockKermesseModel(string $status = 'open'): KermesseModel
    {
        $mock = $this->getMockBuilder(KermesseModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $mock->method('find')->willReturn(['id' => 10, 'name' => 'Kermesse de test', 'status' => $status]);
        return $mock;
    }

    private function buildMockSlotModel(
        int $capacity = 10,
        string $status = SlotModel::STATUS_ACTIVE,
        ?string $endsAt = null,
    ): SlotModel {
        $mock = $this->getMockBuilder(SlotModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findForCapacityCheck'])
            ->getMock();
        $mock->method('findForCapacityCheck')->willReturn([
            'id'         => 1,
            'stand_id'   => 5,
            'capacity'   => $capacity,
            'status'     => $status,
            'starts_at'  => '2026-09-12 09:00:00',
            'ends_at'    => $endsAt ?? '2026-09-12 10:30:00',
        ]);
        return $mock;
    }

    private function validFields(): array
    {
        return [
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'email'      => 'marie@exemple.fr',
            'phone'      => '0612345678',
        ];
    }

    // ------------------------------------------------------------------
    // Story 5.14 — AC: New email → creates signup with null user_id, no user created
    // ------------------------------------------------------------------

    public function testNewEmailDoesNotCreateUserAndReturnsSuccessWithNullUserId(): void
    {
        $userMock = $this->buildMockUserModel(42);
        $userMock->method('findByEmailHash')->willReturn(null);
        $userMock->expects($this->never())->method('insert');

        $slotSignupMock = $this->buildMockSlotSignupModel(99);
        $slotSignupMock->expects($this->once())->method('insert')->willReturnCallback(function (array $data) {
            $this->assertNull($data['user_id']);
            $this->assertSame('marie@exemple.fr', $data['email']);
            return 99;
        });

        $service = $this->buildService($userMock, $slotSignupMock);
        $result  = $service->signup(1, 10, $this->validFields());

        $this->assertInstanceOf(SlotSignupResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(99, $result->signupId);
        $this->assertNull($result->volunteerId);
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC2: Existing email → reuse user
    // ------------------------------------------------------------------

    public function testExistingEmailReusesUserWithoutCreating(): void
    {
        $existingUser = ['id' => 7, 'email' => 'marie@exemple.fr'];

        $userMock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $userMock->method('findByEmailHash')->willReturn($existingUser);
        $userMock->expects($this->never())->method('insert');

        $slotSignupMock = $this->buildMockSlotSignupModel(100);
        $slotSignupMock->expects($this->once())->method('insert');

        $service = $this->buildService($userMock, $slotSignupMock);
        $result  = $service->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
        $this->assertSame(7, $result->volunteerId);
    }

    // ------------------------------------------------------------------
    // Story 3.3 — AC3: Email normalization
    // ------------------------------------------------------------------

    public function testEmailIsNormalizedBeforeLookup(): void
    {
        $capturedHash = null;

        $userMock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $userMock->method('findByEmailHash')
            ->willReturnCallback(function (string $emailHash) use (&$capturedHash) {
                $capturedHash = $emailHash;
                return null;
            });
        $userMock->method('skipValidation')->willReturnSelf();
        $userMock->method('insert')->willReturn(1);

        $fields = array_merge($this->validFields(), ['email' => '  TEST@EXAMPLE.COM  ']);

        $service = $this->buildService($userMock, $this->buildMockSlotSignupModel());
        $service->signup(1, 10, $fields);

        $this->assertSame(
            hash('sha256', 'test@example.com'),
            $capturedHash,
            'Email must be lowercased and trimmed before hashing for lookup',
        );
    }

    public function testNormalizedEmailStoredOnNewSignup(): void
    {
        $capturedData = null;

        $userMock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $userMock->method('findByEmailHash')->willReturn(null);
        $userMock->method('skipValidation')->willReturnSelf();
        $userMock->expects($this->never())->method('insert');

        $slotSignupMock = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert', 'countActiveForSlot', 'findActiveByEmailOrUserAndSlot', 'findOverlappingActiveByEmailOrUser'])
            ->getMock();
        $slotSignupMock->method('skipValidation')->willReturnSelf();
        $slotSignupMock->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $fields = array_merge($this->validFields(), ['email' => 'Marie@Example.FR']);

        $service = $this->buildService($userMock, $slotSignupMock);
        $service->signup(1, 10, $fields);

        $this->assertSame('marie@example.fr', $capturedData['email'] ?? null);
    }

    // ------------------------------------------------------------------
    // Signup row carries the correct user_id and slot_id
    // ------------------------------------------------------------------

    public function testSignupInsertedWithCorrectUserIdAndSlotId(): void
    {
        $capturedSignup = null;

        $userMock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $userMock->method('skipValidation')->willReturnSelf();
        $userMock->method('insert')->willReturn(55);
        $userMock->method('findByEmailHash')->willReturn(['id' => 55, 'email' => 'marie@exemple.fr']);

        $slotSignupMock = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert', 'countActiveForSlot', 'findActiveByEmailOrUserAndSlot', 'findOverlappingActiveByEmailOrUser'])
            ->getMock();
        $slotSignupMock->method('skipValidation')->willReturnSelf();
        $slotSignupMock->method('insert')->willReturnCallback(function (array $data) use (&$capturedSignup) {
            $capturedSignup = $data;
            return 77;
        });

        $service = $this->buildService($userMock, $slotSignupMock);
        $service->signup(slotId: 3, kermesseId: 10, fields: $this->validFields());

        $this->assertSame(3,  $capturedSignup['slot_id'] ?? null);
        $this->assertSame(55, $capturedSignup['user_id'] ?? null);
        $this->assertArrayNotHasKey('status', $capturedSignup);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC4: Kermesse must be open
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenKermesseNotOpen(): void
    {
        $service = $this->buildService(
            kermesseModel: $this->buildMockKermesseModel('closed'),
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('signups_not_open', $result->errorCode);
    }

    public function testSignupRefusedWhenKermesseInPreparation(): void
    {
        $service = $this->buildService(
            kermesseModel: $this->buildMockKermesseModel('preparation'),
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('signups_not_open', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC1: Slot capacity enforcement
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenSlotFull(): void
    {
        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('countActiveForSlot')->willReturn(3);

        $service = $this->buildService(
            slotSignupModel: $slotSignupMock,
            slotModel:       $this->buildMockSlotModel(3), // capacity 3, count 3 → full
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('slot_full', $result->errorCode);
    }

    public function testSignupAllowedWhenOneSlotRemains(): void
    {
        $slotSignupMock = $this->buildMockSlotSignupModel(77);
        $slotSignupMock->method('countActiveForSlot')->willReturn(2);

        $service = $this->buildService(
            slotSignupModel: $slotSignupMock,
            slotModel:       $this->buildMockSlotModel(3), // capacity 3, count 2 → still 1 place
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC2: Duplicate signup detection
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenDuplicateExists(): void
    {
        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('findActiveByEmailOrUserAndSlot')->willReturn(['id' => 5, 'status' => 'active']);

        $service = $this->buildService(slotSignupModel: $slotSignupMock);

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('duplicate_signup', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC3: Overlap detection
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenOverlapConflict(): void
    {
        $conflictingSlot = [
            'id'        => 99,
            'starts_at' => '2026-09-12 08:30:00',
            'ends_at'   => '2026-09-12 10:00:00',
        ];

        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('findOverlappingActiveByEmailOrUser')->willReturn($conflictingSlot);

        $service = $this->buildService(slotSignupModel: $slotSignupMock);

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('overlap_conflict', $result->errorCode);
        $this->assertSame('2026-09-12 08:30:00', $result->context['conflicting_starts_at']);
        $this->assertSame('2026-09-12 10:00:00', $result->context['conflicting_ends_at']);
    }

    public function testOverlapContextCarriesConflictingTimes(): void
    {
        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('findOverlappingActiveByEmailOrUser')->willReturn([
            'starts_at' => '2026-09-12 11:00:00',
            'ends_at'   => '2026-09-12 12:30:00',
        ]);

        $result = $this->buildService(slotSignupModel: $slotSignupMock)->signup(1, 10, $this->validFields());

        $this->assertSame('2026-09-12 11:00:00', $result->context['conflicting_starts_at'] ?? null);
        $this->assertSame('2026-09-12 12:30:00', $result->context['conflicting_ends_at']   ?? null);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC5: Capacity checked before duplicate/overlap
    // ------------------------------------------------------------------

    public function testCapacityCheckedBeforeDuplicate(): void
    {
        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('countActiveForSlot')->willReturn(5);
        $slotSignupMock->method('findActiveByEmailOrUserAndSlot')->willReturn(['id' => 5, 'status' => 'active']);

        $service = $this->buildService(
            slotSignupModel: $slotSignupMock,
            slotModel:       $this->buildMockSlotModel(5), // full
        );

        $result = $service->signup(1, 10, $this->validFields());

        // slot_full must be returned, not duplicate_signup
        $this->assertSame('slot_full', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Slot state is a service-owned invariant: the public summary filter
    // must not be the only guard (admin-deactivation race, direct callers)
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenSlotDeactivated(): void
    {
        $service = $this->buildService(
            slotModel: $this->buildMockSlotModel(status: SlotModel::STATUS_DEACTIVATED),
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('slot_unavailable', $result->errorCode);
    }

    public function testSignupRefusedWhenSlotAlreadyEnded(): void
    {
        $service = $this->buildService(
            slotModel: $this->buildMockSlotModel(endsAt: '2020-01-01 10:00:00'),
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('slot_unavailable', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Transaction robustness
    // ------------------------------------------------------------------

    public function testTransactionBeginFailureReturnsTransactionFailed(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('transBegin')->willReturn(false);

        $result = $this->buildService(db: $db)->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('transaction_failed', $result->errorCode);
    }

    public function testOverlapCheckFailureFailsClosed(): void
    {
        // A failed overlap check must abort the signup, never pass as "no overlap"
        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('findOverlappingActiveByEmailOrUser')
            ->willThrowException(new DatabaseException('overlap check failed'));

        $result = $this->buildService(slotSignupModel: $slotSignupMock)->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('transaction_failed', $result->errorCode);
    }

    // Removed testUserInsertRaceFallsBackToExistingUser because we no longer insert a user during signup

    // ------------------------------------------------------------------
    // Story 3.5 — confirmation email sent after commit, never blocking
    // ------------------------------------------------------------------

    public function testSuccessfulSignupSendsConfirmationEmailWithSlotDetails(): void
    {
        $capturedArgs = null;

        $emailMock = $this->createMock(EmailService::class);
        $emailMock->expects($this->once())
            ->method('sendSignupConfirmationEmail')
            ->willReturnCallback(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return new EmailDeliveryResult(true, null);
            });

        $result = $this->buildService(emailService: $emailMock)
            ->signup(1, 10, array_merge($this->validFields(), ['email' => ' Marie@Exemple.FR ']));

        $this->assertTrue($result->success);
        $this->assertTrue($result->emailSent);
        // recipient (normalized), firstName, kermesseName, standName, startsAt, endsAt
        $this->assertSame('marie@exemple.fr', $capturedArgs[0]);
        $this->assertSame('Marie', $capturedArgs[1]);
        $this->assertSame('Kermesse de test', $capturedArgs[2]);
        $this->assertSame('Buvette', $capturedArgs[3]);
        $this->assertSame('2026-09-12 09:00:00', $capturedArgs[4]);
        $this->assertSame('2026-09-12 10:30:00', $capturedArgs[5]);
    }

    public function testRefusedSignupSendsNoEmail(): void
    {
        $emailMock = $this->createMock(EmailService::class);
        $emailMock->expects($this->never())->method('sendSignupConfirmationEmail');

        $slotSignupMock = $this->buildMockSlotSignupModel();
        $slotSignupMock->method('findActiveByEmailOrUserAndSlot')->willReturn(['id' => 5, 'status' => 'active']);

        $result = $this->buildService(slotSignupModel: $slotSignupMock, emailService: $emailMock)
            ->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertNull($result->emailSent);
    }

    public function testEmailSendFailureDoesNotFailSignup(): void
    {
        $result = $this->buildService(emailService: $this->buildMockEmailService(sent: false))
            ->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success, 'Signup must stay confirmed when the email fails (AC4)');
        $this->assertFalse($result->emailSent);
    }

    // ------------------------------------------------------------------
    // Story 3.5 — Magic Link URL passed to EmailService
    // ------------------------------------------------------------------

    public function testMagicLinkUrlPassedToEmailService(): void
    {
        $tokenMock = $this->createMock(TokenService::class);
        $tokenMock->method('issueMagicLink')
            ->willReturn(new IssuedToken('special-raw-token', 1));

        $capturedArgs = null;
        $emailMock    = $this->createMock(EmailService::class);
        $emailMock->expects($this->once())
            ->method('sendSignupConfirmationEmail')
            ->willReturnCallback(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return new EmailDeliveryResult(true, null);
            });

        $result = $this->buildService(emailService: $emailMock, tokenService: $tokenMock)
            ->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
        $this->assertIsString($capturedArgs[6] ?? null, 'Magic link URL must be the 7th argument to sendSignupConfirmationEmail');
        $this->assertStringContainsString('special-raw-token', $capturedArgs[6]);
    }

    public function testMagicLinkTokenGenerationFailureStillSendsEmail(): void
    {
        $tokenMock = $this->createMock(TokenService::class);
        $tokenMock->method('issueMagicLink')
            ->willThrowException(new \RuntimeException('Token DB unavailable'));

        $capturedArgs = null;
        $emailMock    = $this->createMock(EmailService::class);
        $emailMock->expects($this->once())
            ->method('sendSignupConfirmationEmail')
            ->willReturnCallback(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return new EmailDeliveryResult(true, null);
            });

        $result = $this->buildService(emailService: $emailMock, tokenService: $tokenMock)
            ->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
        $this->assertTrue($result->emailSent, 'Email must still be sent when magic link token generation fails');
        $this->assertCount(7, $capturedArgs, 'sendSignupConfirmationEmail must always receive 7 arguments');
        $this->assertSame('', $capturedArgs[6], 'Magic link URL must be empty string when token generation fails');
    }

    public function testEmailExceptionDoesNotFailSignup(): void
    {
        $emailMock = $this->createMock(EmailService::class);
        $emailMock->method('sendSignupConfirmationEmail')
            ->willThrowException(new \RuntimeException('SMTP exploded'));

        $result = $this->buildService(emailService: $emailMock)->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success, 'Signup must stay confirmed when the email throws (AC4)');
        $this->assertFalse($result->emailSent);
    }

    // ------------------------------------------------------------------
    // Story 3.2 — AC3: Profile divergence detection
    //
    // When a public signup provides different name/phone than the stored profile,
    // SlotSignupService must insert a profile_divergences row in the same transaction,
    // without blocking the signup success.
    // ------------------------------------------------------------------

    private function buildExistingUserMock(array $storedProfile): UserModel
    {
        $mock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $mock->method('findByEmailHash')->willReturn($storedProfile);
        $mock->expects($this->never())->method('insert');
        return $mock;
    }


    // ------------------------------------------------------------------
    // Story 4.3 — cancelSlotSignup: ownership, lifecycle, instant slot recovery
    // ------------------------------------------------------------------

    /** SlotSignupModel mock exposing only the two cancellation seams. */
    private function buildCancelSlotSignupModel(?array $owned): SlotSignupModel
    {
        $mock = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findActiveOwnedInKermesse', 'markCancelled'])
            ->getMock();
        $mock->method('findActiveOwnedInKermesse')->willReturn($owned);

        return $mock;
    }

    public function testCancelSignupSucceedsForOwnerWhenOpen(): void
    {
        $slotSignupMock = $this->buildCancelSlotSignupModel(['id' => 5, 'user_id' => 42, 'slot_id' => 3]);
        $slotSignupMock->expects($this->once())->method('markCancelled')->with(5, 42)->willReturn(true);

        $result = $this->buildService(
            slotSignupModel: $slotSignupMock,
            kermesseModel:   $this->buildMockKermesseModel('open'),
        )->cancelSlotSignup(5,42, 10);

        $this->assertTrue($result->success);
        $this->assertSame(5, $result->signupId);
    }

    public function testCancelSignupFailsAndDoesNotMutateWhenNotOwner(): void
    {
        // findActiveOwnedInKermesse returns null when the signup is not the user's
        // (or belongs to another kermesse): markCancelled must never be reached.
        $slotSignupMock = $this->buildCancelSlotSignupModel(null);
        $slotSignupMock->expects($this->never())->method('markCancelled');

        $result = $this->buildService(
            slotSignupModel: $slotSignupMock,
            kermesseModel:   $this->buildMockKermesseModel('open'),
        )->cancelSlotSignup(5,999, 10);

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);
    }

    public function testCancelSignupRefusedWhenKermesseClosed(): void
    {
        $slotSignupMock = $this->buildCancelSlotSignupModel(['id' => 5, 'user_id' => 42, 'slot_id' => 3]);
        $slotSignupMock->expects($this->never())->method('markCancelled');

        $result = $this->buildService(
            slotSignupModel: $slotSignupMock,
            kermesseModel:   $this->buildMockKermesseModel('closed'),
        )->cancelSlotSignup(5,42, 10);

        $this->assertFalse($result->success);
        $this->assertSame('signups_not_open', $result->errorCode);
    }

    public function testCancelSignupRefusedWhenKermesseInPreparation(): void
    {
        $slotSignupMock = $this->buildCancelSlotSignupModel(['id' => 5, 'user_id' => 42, 'slot_id' => 3]);

        $result = $this->buildService(
            slotSignupModel: $slotSignupMock,
            kermesseModel:   $this->buildMockKermesseModel('preparation'),
        )->cancelSlotSignup(5,42, 10);

        $this->assertFalse($result->success);
        $this->assertSame('signups_not_open', $result->errorCode);
    }

    public function testCancelSignupReturnsFailureWhenMarkCancelledFails(): void
    {
        $slotSignupMock = $this->buildCancelSlotSignupModel(['id' => 5, 'user_id' => 42, 'slot_id' => 3]);
        $slotSignupMock->method('markCancelled')->willReturn(false);

        $result = $this->buildService(
            slotSignupModel: $slotSignupMock,
            kermesseModel:   $this->buildMockKermesseModel('open'),
        )->cancelSlotSignup(5,42, 10);

        $this->assertFalse($result->success);
        $this->assertSame('cancel_failed', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Story 5.1 — stampAdminModification() délègue au modèle
    // ------------------------------------------------------------------

    public function testStampAdminModificationDelegatesToModelAndReturnsTrue(): void
    {
        $slotSignupMock = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['stampAdminModification'])
            ->getMock();
        $slotSignupMock->expects($this->once())
            ->method('stampAdminModification')
            ->with(7, 42)
            ->willReturn(true);

        $result = $this->buildService(slotSignupModel: $slotSignupMock)->stampAdminModification(7, 42);

        $this->assertTrue($result);
    }

    public function testStampAdminModificationReturnsFalseOnModelMiss(): void
    {
        $slotSignupMock = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['stampAdminModification'])
            ->getMock();
        $slotSignupMock->method('stampAdminModification')->willReturn(false);

        $result = $this->buildService(slotSignupModel: $slotSignupMock)->stampAdminModification(99999, 42);

        $this->assertFalse($result);
    }
}
