<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use App\Models\KermesseModel;
use App\Models\ProfileDivergenceModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Services\EmailDeliveryResult;
use App\Services\EmailService;
use App\Services\SignupService;
use App\Services\SignupResult;
use App\Models\UserModel;
use App\Models\SignupModel;

/**
 * Unit tests for SignupService — Stories 3.2, 3.3 & 3.4.
 *
 * All DB access is mocked — including the transaction connection — so tests are
 * fast and fully isolated from any real database.
 * Story 3.3: user create/reuse, email normalization, insert-race fallback.
 * Story 3.4: kermesse open, slot state/capacity, duplicate, overlap constraints.
 *
 * @internal
 */
final class SignupServiceTest extends CIUnitTestCase
{
    private function buildService(
        ?UserModel               $userModel              = null,
        ?SignupModel              $signupModel            = null,
        ?KermesseModel           $kermesseModel          = null,
        ?SlotModel               $slotModel              = null,
        ?BaseConnection          $db                     = null,
        ?EmailService            $emailService           = null,
        ?StandModel              $standModel             = null,
        ?ProfileDivergenceModel  $profileDivergenceModel = null,
    ): SignupService {
        return new SignupService(
            $userModel              ?? $this->buildMockUserModel(),
            $signupModel            ?? $this->buildMockSignupModel(),
            $kermesseModel          ?? $this->buildMockKermesseModel(),
            $slotModel              ?? $this->buildMockSlotModel(),
            $db                     ?? $this->buildMockConnection(),
            $emailService           ?? $this->buildMockEmailService(),
            $standModel             ?? $this->buildMockStandModel(),
            $profileDivergenceModel ?? $this->buildMockProfileDivergenceModel(),
        );
    }

    private function buildMockProfileDivergenceModel(): ProfileDivergenceModel
    {
        $mock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn(1);
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
        $mock = $this->createMock(BaseConnection::class);
        $mock->method('transBegin')->willReturn(true);
        $mock->method('transCommit')->willReturn(true);
        return $mock;
    }

    private function buildMockUserModel(int $returnedId = 42): UserModel
    {
        $mock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert', 'lockForOverlapCheck'])
            ->getMock();
        $mock->method('findByEmailHash')->willReturn(null);
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn($returnedId);
        return $mock;
    }

    /**
     * Build a SignupModel mock with insert preconfigured.
     * countActiveForSlot/findActiveByUserAndSlot/findOverlappingActiveByUser
     * are left unconfigured so callers can override without FIFO stub conflicts.
     * Unconfigured mock methods return null — which the service treats as "no issue" (0
     * active signups, no duplicate, no overlap) for happy-path tests.
     */
    private function buildMockSignupModel(int $returnedId = 99): SignupModel
    {
        $mock = $this->getMockBuilder(SignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert', 'countActiveForSlot', 'findActiveByUserAndSlot', 'findOverlappingActiveByUser'])
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
    // Story 3.3 — AC1: New email → creates user + signup
    // ------------------------------------------------------------------

    public function testNewEmailCreatesUserAndReturnsSuccess(): void
    {
        $userMock = $this->buildMockUserModel(42);
        $userMock->method('findByEmailHash')->willReturn(null);
        $userMock->expects($this->once())->method('insert');

        $signupMock = $this->buildMockSignupModel(99);
        $signupMock->expects($this->once())->method('insert');

        $service = $this->buildService($userMock, $signupMock);
        $result  = $service->signup(1, 10, $this->validFields());

        $this->assertInstanceOf(SignupResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(99, $result->signupId);
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

        $signupMock = $this->buildMockSignupModel(100);
        $signupMock->expects($this->once())->method('insert');

        $service = $this->buildService($userMock, $signupMock);
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

        $service = $this->buildService($userMock, $this->buildMockSignupModel());
        $service->signup(1, 10, $fields);

        $this->assertSame(
            hash('sha256', 'test@example.com'),
            $capturedHash,
            'Email must be lowercased and trimmed before hashing for lookup',
        );
    }

    public function testNormalizedEmailStoredOnNewUser(): void
    {
        $capturedData = null;

        $userMock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert'])
            ->getMock();
        $userMock->method('findByEmailHash')->willReturn(null);
        $userMock->method('skipValidation')->willReturnSelf();
        $userMock->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $fields = array_merge($this->validFields(), ['email' => 'Marie@Example.FR']);

        $service = $this->buildService($userMock, $this->buildMockSignupModel());
        $service->signup(1, 10, $fields);

        $this->assertSame('marie@example.fr', $capturedData['email'] ?? null);
    }

    // ------------------------------------------------------------------
    // Signup row carries the correct user_id and slot_id
    // ------------------------------------------------------------------

    public function testSignupInsertedWithCorrectUserIdAndSlotId(): void
    {
        $capturedSignup = null;

        $userMock = $this->buildMockUserModel(55);
        $userMock->method('findByEmailHash')->willReturn(null);

        $signupMock = $this->getMockBuilder(SignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert', 'countActiveForSlot', 'findActiveByUserAndSlot', 'findOverlappingActiveByUser'])
            ->getMock();
        $signupMock->method('skipValidation')->willReturnSelf();
        $signupMock->method('insert')->willReturnCallback(function (array $data) use (&$capturedSignup) {
            $capturedSignup = $data;
            return 77;
        });

        $service = $this->buildService($userMock, $signupMock);
        $service->signup(slotId: 3, kermesseId: 10, fields: $this->validFields());

        $this->assertSame(3,  $capturedSignup['slot_id'] ?? null);
        $this->assertSame(55, $capturedSignup['user_id'] ?? null);
        $this->assertSame('active', $capturedSignup['status'] ?? null);
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
        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('countActiveForSlot')->willReturn(3);

        $service = $this->buildService(
            signupModel: $signupMock,
            slotModel:   $this->buildMockSlotModel(3), // capacity 3, count 3 → full
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('slot_full', $result->errorCode);
    }

    public function testSignupAllowedWhenOneSlotRemains(): void
    {
        $signupMock = $this->buildMockSignupModel(77);
        $signupMock->method('countActiveForSlot')->willReturn(2);

        $service = $this->buildService(
            signupModel: $signupMock,
            slotModel:   $this->buildMockSlotModel(3), // capacity 3, count 2 → still 1 place
        );

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC2: Duplicate signup detection
    // ------------------------------------------------------------------

    public function testSignupRefusedWhenDuplicateExists(): void
    {
        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('findActiveByUserAndSlot')->willReturn(['id' => 5, 'status' => 'active']);

        $service = $this->buildService(signupModel: $signupMock);

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

        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('findOverlappingActiveByUser')->willReturn($conflictingSlot);

        $service = $this->buildService(signupModel: $signupMock);

        $result = $service->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('overlap_conflict', $result->errorCode);
        $this->assertSame('2026-09-12 08:30:00', $result->context['conflicting_starts_at']);
        $this->assertSame('2026-09-12 10:00:00', $result->context['conflicting_ends_at']);
    }

    public function testOverlapContextCarriesConflictingTimes(): void
    {
        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('findOverlappingActiveByUser')->willReturn([
            'starts_at' => '2026-09-12 11:00:00',
            'ends_at'   => '2026-09-12 12:30:00',
        ]);

        $result = $this->buildService(signupModel: $signupMock)->signup(1, 10, $this->validFields());

        $this->assertSame('2026-09-12 11:00:00', $result->context['conflicting_starts_at'] ?? null);
        $this->assertSame('2026-09-12 12:30:00', $result->context['conflicting_ends_at']   ?? null);
    }

    // ------------------------------------------------------------------
    // Story 3.4 — AC5: Capacity checked before duplicate/overlap
    // ------------------------------------------------------------------

    public function testCapacityCheckedBeforeDuplicate(): void
    {
        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('countActiveForSlot')->willReturn(5);
        $signupMock->method('findActiveByUserAndSlot')->willReturn(['id' => 5, 'status' => 'active']);

        $service = $this->buildService(
            signupModel: $signupMock,
            slotModel:   $this->buildMockSlotModel(5), // full
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
        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('findOverlappingActiveByUser')
            ->willThrowException(new DatabaseException('overlap check failed'));

        $result = $this->buildService(signupModel: $signupMock)->signup(1, 10, $this->validFields());

        $this->assertFalse($result->success);
        $this->assertSame('transaction_failed', $result->errorCode);
    }

    // ------------------------------------------------------------------
    // Story 3.3 — user insert race: the duplicate-key loser must reuse
    // the competitor's committed user via the locking re-read
    // ------------------------------------------------------------------

    public function testUserInsertRaceFallsBackToExistingUser(): void
    {
        $userMock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert', 'lockForOverlapCheck'])
            ->getMock();
        // Plain lookup misses (stale snapshot), insert loses the unique-key race,
        // the locking re-read then sees the competitor's committed row.
        // Third call: divergence-check locking read — returns same existing row.
        $userMock->method('findByEmailHash')
            ->willReturnOnConsecutiveCalls(
                null,
                ['id' => 7, 'email' => 'marie@exemple.fr'],
                ['id' => 7, 'email' => 'marie@exemple.fr', 'first_name' => 'Marie', 'last_name' => 'Dupont', 'phone' => '0612345678'],
            );
        $userMock->method('skipValidation')->willReturnSelf();
        $userMock->method('insert')
            ->willThrowException(new DatabaseException('Duplicate entry for uq_users_email_hash'));

        $signupMock = $this->buildMockSignupModel(123);
        $signupMock->expects($this->once())->method('insert');

        $result = $this->buildService($userMock, $signupMock)->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
        $this->assertSame(7, $result->volunteerId);
    }

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

        $signupMock = $this->buildMockSignupModel();
        $signupMock->method('findActiveByUserAndSlot')->willReturn(['id' => 5, 'status' => 'active']);

        $result = $this->buildService(signupModel: $signupMock, emailService: $emailMock)
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
    // SignupService must insert a profile_divergences row in the same transaction,
    // without blocking the signup success.
    // ------------------------------------------------------------------

    private function buildExistingUserMock(array $storedProfile): UserModel
    {
        $mock = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'skipValidation', 'insert', 'lockForOverlapCheck'])
            ->getMock();
        $mock->method('findByEmailHash')->willReturn($storedProfile);
        $mock->expects($this->never())->method('insert');
        return $mock;
    }

    public function testExistingUserWithSameProfileDoesNotInsertDivergence(): void
    {
        $stored = ['id' => 7, 'email' => 'marie@exemple.fr', 'first_name' => 'Marie', 'last_name' => 'Dupont', 'phone' => '0612345678'];

        $pdMock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $pdMock->method('skipValidation')->willReturnSelf();
        $pdMock->expects($this->never())->method('insert');

        $result = $this->buildService(
            userModel:              $this->buildExistingUserMock($stored),
            profileDivergenceModel: $pdMock,
        )->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
    }

    public function testExistingUserWithDifferentFirstNameInsertsProfileDivergence(): void
    {
        $stored = ['id' => 7, 'email' => 'marie@exemple.fr', 'first_name' => 'Maria', 'last_name' => 'Dupont', 'phone' => '0612345678'];

        $capturedData = null;
        $pdMock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $pdMock->method('skipValidation')->willReturnSelf();
        $pdMock->expects($this->once())->method('insert')
            ->willReturnCallback(function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return 1;
            });

        $result = $this->buildService(
            userModel:              $this->buildExistingUserMock($stored),
            profileDivergenceModel: $pdMock,
        )->signup(slotId: 1, kermesseId: 10, fields: $this->validFields());

        $this->assertTrue($result->success);
        $this->assertSame('Marie', $capturedData['submitted_first_name'] ?? null, 'Submitted first_name must be recorded');
        $this->assertSame(7,      $capturedData['user_id']               ?? null);
        $this->assertSame(10,     $capturedData['kermesse_id']           ?? null);
    }

    public function testExistingUserWithDifferentPhoneInsertsProfileDivergence(): void
    {
        $stored = ['id' => 7, 'email' => 'marie@exemple.fr', 'first_name' => 'Marie', 'last_name' => 'Dupont', 'phone' => '0600000000'];

        $pdMock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $pdMock->method('skipValidation')->willReturnSelf();
        $pdMock->expects($this->once())->method('insert')->willReturn(1);

        $result = $this->buildService(
            userModel:              $this->buildExistingUserMock($stored),
            profileDivergenceModel: $pdMock,
        )->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
    }

    public function testNewUserDoesNotInsertProfileDivergence(): void
    {
        // Default buildMockUserModel returns null for findByEmailHash (new user)
        $pdMock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $pdMock->method('skipValidation')->willReturnSelf();
        $pdMock->expects($this->never())->method('insert');

        $result = $this->buildService(profileDivergenceModel: $pdMock)->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success);
    }

    public function testEmptySubmittedPhoneDoesNotTriggerDivergence(): void
    {
        // Stored phone = '0600000000', submitted phone = '' (field left blank — optional)
        $stored = ['id' => 7, 'email' => 'marie@exemple.fr', 'first_name' => 'Marie', 'last_name' => 'Dupont', 'phone' => '0600000000'];

        $pdMock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $pdMock->method('skipValidation')->willReturnSelf();
        $pdMock->expects($this->never())->method('insert');

        $fieldsNoPhone = array_merge($this->validFields(), ['phone' => '']);

        $result = $this->buildService(
            userModel:              $this->buildExistingUserMock($stored),
            profileDivergenceModel: $pdMock,
        )->signup(1, 10, $fieldsNoPhone);

        $this->assertTrue($result->success);
    }

    public function testDivergenceInsertFailureDoesNotFailSignup(): void
    {
        $stored = ['id' => 7, 'email' => 'marie@exemple.fr', 'first_name' => 'OtherName', 'last_name' => 'Dupont', 'phone' => ''];

        $pdMock = $this->getMockBuilder(ProfileDivergenceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['skipValidation', 'insert'])
            ->getMock();
        $pdMock->method('skipValidation')->willReturnSelf();
        $pdMock->method('insert')->willThrowException(new \RuntimeException('DB error'));

        $result = $this->buildService(
            userModel:              $this->buildExistingUserMock($stored),
            profileDivergenceModel: $pdMock,
        )->signup(1, 10, $this->validFields());

        $this->assertTrue($result->success, 'Signup must succeed even when the divergence insert fails');
    }
}
