<?php

declare(strict_types=1);

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use App\Models\KermesseModel;
use App\Models\SlotSignupModel;
use App\Models\SlotModel;
use App\Models\StandModel;
use App\Models\UserModel;
use App\Services\AdminCreateSlotSignupDTO;
use App\Services\EmailDeliveryResult;
use App\Services\EmailService;
use App\Services\IssuedToken;
use App\Services\SlotSignupResult;
use App\Services\SlotSignupService;
use App\Services\TokenService;

/**
 * Unit tests for SlotSignupService admin actions — Story 5.10.
 *
 * adminCancelSlotSignup: bypass signups_not_open, optional notification email,
 *   stamps last_modified_by_user_id / last_modified_at.
 * adminEditSlotSignup: writes only to slot_signups table, checks first_access_at lock,
 *   never mutates users table.
 *
 * All DB access is mocked: these are pure unit tests with no DB dependency.
 *
 * @internal
 */
final class SlotSignupServiceAdminTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // adminCancelSlotSignup — bypass signups_not_open
    // ------------------------------------------------------------------

    public function testAdminCancelBypasesSignupsNotOpen(): void
    {
        // Kermesse is CLOSED — volunteer cancel would fail with signups_not_open,
        // but admin cancel must succeed regardless.
        $closedKermesse = ['id' => 1, 'name' => 'K', 'status' => 'closed'];

        $signupRow = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@example.com', 'stand_name' => 'Stand A', 'starts_at' => '2026-06-20 10:00:00', 'ends_at' => '2026-06-20 12:00:00'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->with(10, 1)->willReturn($signupRow);
        $slotSignupModel->method('markCancelledByAdmin')->with(10, 7)->willReturn(true);
        $slotSignupModel->method('stampAdminModification')->with(10, 7)->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->with(1)->willReturn($closedKermesse);

        $service = $this->buildService(slotSignupModel: $slotSignupModel, kermesseModel: $kermesseModel);

        $result = $service->adminCancelSlotSignup(slotSignupId:10, adminUserId: 7, kermesseId: 1, notify: false);

        $this->assertTrue($result->success);
        $this->assertSame(10, $result->signupId);
    }

    public function testAdminCancelReturnsNotFoundWhenSignupMissing(): void
    {
        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->with(99, 1)->willReturn(null);

        $service = $this->buildService(slotSignupModel: $slotSignupModel);

        $result = $service->adminCancelSlotSignup(slotSignupId:99, adminUserId: 7, kermesseId: 1);

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);
    }

    public function testAdminCancelReturnsCancelFailedWhenMarkFails(): void
    {
        $signupRow = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@example.com'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->method('markCancelledByAdmin')->willReturn(false);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn(['id' => 1, 'status' => 'open']);

        $service = $this->buildService(slotSignupModel: $slotSignupModel, kermesseModel: $kermesseModel);

        $result = $service->adminCancelSlotSignup(slotSignupId:10, adminUserId: 7, kermesseId: 1);

        $this->assertFalse($result->success);
        $this->assertSame('cancel_failed', $result->errorCode);
    }

    public function testAdminCancelSendsEmailWhenNotifyTrue(): void
    {
        $signupRow = [
            'id' => 10, 'user_id' => 99, 'slot_id' => 5,
            'email'      => 'vol@example.com',
            'first_name' => 'Marie',
            'last_name'  => 'Dupont',
            'stand_name' => 'Pâtisseries',
            'starts_at'  => '2026-06-20 14:00:00',
            'ends_at'    => '2026-06-20 16:00:00',
        ];
        $kermesseRow = ['id' => 1, 'name' => 'Kermesse Test', 'status' => 'open'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->method('markCancelledByAdmin')->willReturn(true);
        $slotSignupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendSignupCancellationEmail')
            ->with('vol@example.com', 'Marie', 'Kermesse Test', 'Pâtisseries (20/06 14h – 16h)')
            ->willReturn(new EmailDeliveryResult(true, null));

        $service = $this->buildService(
            slotSignupModel: $slotSignupModel,
            kermesseModel: $kermesseModel,
            emailService: $emailService,
        );

        $result = $service->adminCancelSlotSignup(slotSignupId:10, adminUserId: 7, kermesseId: 1, notify: true);

        $this->assertTrue($result->success);
        $this->assertTrue($result->emailSent);
        $this->assertSame('Marie Dupont', $result->context['volunteer_name']);
        $this->assertSame('Pâtisseries (20/06 14h – 16h)', $result->context['slot_label']);
    }

    public function testAdminCancelDoesNotSendEmailWhenNotifyFalse(): void
    {
        $signupRow   = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@example.com', 'first_name' => 'A', 'last_name' => 'B', 'stand_name' => 'Stand A', 'starts_at' => '2026-06-20 10:00:00', 'ends_at' => '2026-06-20 12:00:00'];
        $kermesseRow = ['id' => 1, 'name' => 'K', 'status' => 'open'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->method('markCancelledByAdmin')->willReturn(true);
        $slotSignupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendSignupCancellationEmail');

        $service = $this->buildService(
            slotSignupModel: $slotSignupModel,
            kermesseModel: $kermesseModel,
            emailService: $emailService,
        );

        $result = $service->adminCancelSlotSignup(slotSignupId:10, adminUserId: 7, kermesseId: 1, notify: false);

        $this->assertTrue($result->success);
        $this->assertNull($result->emailSent);
    }

    public function testAdminCancelStampsModificationColumns(): void
    {
        $signupRow   = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@test.com', 'first_name' => 'X', 'last_name' => 'Y', 'stand_name' => 'Stand B', 'starts_at' => '2026-06-20 10:00:00', 'ends_at' => '2026-06-20 12:00:00'];
        $kermesseRow = ['id' => 1, 'name' => 'K', 'status' => 'preparation'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->method('markCancelledByAdmin')->willReturn(true);
        // stampAdminModification MUST be called with the admin user id.
        $slotSignupModel->expects($this->once())
            ->method('stampAdminModification')
            ->with(10, 42)
            ->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $service = $this->buildService(slotSignupModel: $slotSignupModel, kermesseModel: $kermesseModel);

        $service->adminCancelSlotSignup(slotSignupId:10, adminUserId: 42, kermesseId: 1, notify: false);
    }

    // ------------------------------------------------------------------
    // adminEditSlotSignup — edit contact fields on slot_signups table
    // ------------------------------------------------------------------

    public function testAdminEditSucceedsWhenFirstAccessAtIsNull(): void
    {
        $signupRow = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->with(20, 1)->willReturn($signupRow);
        $slotSignupModel->method('updateContactFields')->willReturn(true);
        $slotSignupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $service = $this->buildService(
            slotSignupModel: $slotSignupModel,
            kermesseModel: $kermesseModel,
        );

        $result = $service->adminEditSlotSignup(
            slotSignupId: 20,
            adminUserId: 7,
            kermesseId: 1,
            fields: ['first_name' => 'Alice', 'last_name' => 'Martin', 'email' => 'alice@example.com', 'phone' => '0601020304'],
        );

        $this->assertTrue($result->success);
        $this->assertSame(20, $result->signupId);
    }


    public function testAdminEditReturnsNotFoundWhenSignupMissing(): void
    {
        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn(null);

        $service = $this->buildService(slotSignupModel: $slotSignupModel);

        $result = $service->adminEditSlotSignup(
            slotSignupId: 99, adminUserId: 7, kermesseId: 1,
            fields: ['first_name' => 'Test'],
        );

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);
    }

    public function testAdminEditNeverTouchesUsersTable(): void
    {
        $signupRow   = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->method('updateContactFields')->willReturn(true);
        $slotSignupModel->method('stampAdminModification')->willReturn(true);

        // userModel must NEVER have update called
        $userModel = $this->createMock(UserModel::class);
        $userModel->expects($this->never())->method('update');
        $userModel->expects($this->never())->method('save');
        $userModel->expects($this->never())->method('insert');

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $service = $this->buildService(
            userModel: $userModel,
            slotSignupModel: $slotSignupModel,
            kermesseModel: $kermesseModel,
        );

        $result = $service->adminEditSlotSignup(
            slotSignupId: 20, adminUserId: 7, kermesseId: 1,
            fields: ['first_name' => 'Alice', 'last_name' => 'B', 'email' => 'alice@test.com', 'phone' => ''],
        );

        $this->assertTrue($result->success);
    }

    public function testAdminEditStampsModificationColumns(): void
    {
        $signupRow   = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->method('updateContactFields')->willReturn(true);
        $slotSignupModel->expects($this->once())
            ->method('stampAdminModification')
            ->with(20, 33)
            ->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $service = $this->buildService(
            slotSignupModel: $slotSignupModel,
            kermesseModel: $kermesseModel,
        );

        $service->adminEditSlotSignup(
            slotSignupId: 20, adminUserId: 33, kermesseId: 1,
            fields: ['first_name' => 'Alice', 'last_name' => 'B', 'email' => 'a@b.com', 'phone' => ''],
        );
    }

    public function testAdminEditWritesContactFieldsToSlotSignupsTable(): void
    {
        $signupRow   = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];
        $fields      = ['first_name' => 'Alice', 'last_name' => 'Martin', 'email' => 'alice@test.com', 'phone' => '0601020304'];

        $slotSignupModel = $this->createMock(SlotSignupModel::class);
        $slotSignupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $slotSignupModel->expects($this->once())
            ->method('updateContactFields')
            ->with(20, $fields)
            ->willReturn(true);
        $slotSignupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $service = $this->buildService(
            slotSignupModel: $slotSignupModel,
            kermesseModel: $kermesseModel,
        );

        $service->adminEditSlotSignup(slotSignupId: 20, adminUserId: 7, kermesseId: 1, fields: $fields);
    }

    // ------------------------------------------------------------------
    // createSlotSignupByAdmin — Story 5.11
    // ------------------------------------------------------------------

    public function testCreateSignupByAdminSucceedsWhenKermesseClosed(): void
    {
        // AC3: admin can add signups even when kermesse is "closed".
        $closedKermesse = ['id' => 1, 'name' => 'K', 'status' => 'closed'];

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->with(1)->willReturn($closedKermesse);

        $service = $this->buildServiceForCreate(kermesseModel: $kermesseModel);
        $result  = $service->createSlotSignupByAdmin($this->makeDto(kermesseId: 1));

        $this->assertTrue($result->success);
    }

    public function testCreateSignupByAdminFailsWhenKermesseDoesNotExist(): void
    {
        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn(null);

        $service = $this->buildServiceForCreate(kermesseModel: $kermesseModel);
        $result  = $service->createSlotSignupByAdmin($this->makeDto());

        $this->assertFalse($result->success);
        $this->assertSame('signups_not_open', $result->errorCode);
    }

    public function testCreateSignupByAdminFailsWhenEmailEmpty(): void
    {
        $service = $this->buildServiceForCreate();
        $result  = $service->createSlotSignupByAdmin($this->makeDto(email: ''));

        $this->assertFalse($result->success);
        $this->assertSame('volunteer_insert_failed', $result->errorCode);
    }

    public function testCreateSignupByAdminStampsModification(): void
    {
        // AC3: last_modified_by_user_id must be set to the admin's ID.
        $slotSignupModel = $this->buildMockSlotSignupModelForCreate();
        $slotSignupModel->expects($this->once())
            ->method('stampAdminModification')
            ->with(100, 7)
            ->willReturn(true);

        $service = $this->buildServiceForCreate(slotSignupModel: $slotSignupModel);
        $result  = $service->createSlotSignupByAdmin($this->makeDto(adminUserId: 7));

        $this->assertTrue($result->success);
    }

    public function testCreateSignupByAdminSendsEmailWhenFlagTrue(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendSignupConfirmationEmail')
            ->willReturn(new EmailDeliveryResult(true, null));

        $service = $this->buildServiceForCreate(emailService: $emailService);
        $result  = $service->createSlotSignupByAdmin($this->makeDto(sendConfirmationEmail: true));

        $this->assertTrue($result->success);
        $this->assertTrue($result->emailSent);
    }

    public function testCreateSignupByAdminSkipsEmailWhenFlagFalse(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendSignupConfirmationEmail');

        $service = $this->buildServiceForCreate(emailService: $emailService);
        $result  = $service->createSlotSignupByAdmin($this->makeDto(sendConfirmationEmail: false));

        $this->assertTrue($result->success);
        $this->assertNull($result->emailSent);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildService(
        ?UserModel         $userModel       = null,
        ?SlotSignupModel   $slotSignupModel = null,
        ?KermesseModel     $kermesseModel   = null,
        ?EmailService      $emailService    = null,
    ): SlotSignupService {
        return new SlotSignupService(
            userModel:       $userModel       ?? $this->createMock(UserModel::class),
            slotSignupModel: $slotSignupModel ?? $this->createMock(SlotSignupModel::class),
            kermesseModel:   $kermesseModel   ?? $this->createMock(KermesseModel::class),
            emailService:    $emailService    ?? $this->createMock(EmailService::class),
        );
    }

    /** Build a service fully wired for createSlotSignupByAdmin (needs DB + SlotModel mocks). */
    private function buildServiceForCreate(
        ?KermesseModel     $kermesseModel   = null,
        ?SlotSignupModel   $slotSignupModel = null,
        ?EmailService      $emailService    = null,
    ): SlotSignupService {
        $kermesse = $kermesseModel ?? $this->createMockKermesseModel();

        return new SlotSignupService(
            userModel:       $this->buildMockUserModel(),
            slotSignupModel: $slotSignupModel ?? $this->buildMockSlotSignupModelForCreate(),
            kermesseModel:   $kermesse,
            slotModel:       $this->buildMockSlotModel(),
            db:              $this->buildMockConnection(),
            emailService:    $emailService ?? $this->buildMockEmailService(),
            standModel:      $this->buildMockStandModel(),
            tokenService:    $this->buildMockTokenService(),
        );
    }

    private function buildMockStandModel(): StandModel
    {
        $m = $this->getMockBuilder(StandModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $m->method('find')->willReturn(['id' => 1, 'name' => 'Buvette', 'status' => 'active']);

        return $m;
    }

    private function buildMockTokenService(): TokenService
    {
        $m = $this->createMock(TokenService::class);
        $m->method('issueMagicLink')->willReturn(new IssuedToken('test-token', 1));

        return $m;
    }

    private function createMockKermesseModel(string $status = 'open'): KermesseModel
    {
        $m = $this->getMockBuilder(KermesseModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $m->method('find')->willReturn(['id' => 1, 'name' => 'Kermesse', 'status' => $status]);

        return $m;
    }

    private function buildMockUserModel(): UserModel
    {
        $m = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByEmailHash', 'hashEmail'])
            ->getMock();
        // Return an existing user so findOrCreateUser skips the insert path.
        $m->method('hashEmail')->willReturn('fakehash');
        $m->method('findByEmailHash')->willReturn([
            'id' => 42, 'email' => 'vol@test.com', 'first_name' => 'Alice', 'last_name' => 'Smith',
        ]);

        return $m;
    }

    private function buildMockSlotSignupModelForCreate(int $returnedId = 100): SlotSignupModel
    {
        $m = $this->getMockBuilder(SlotSignupModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'countActiveForSlot',
                'findActiveByEmailOrUserAndSlot',
                'findOverlappingActiveByEmailOrUser',
                'skipValidation',
                'insert',
                'stampAdminModification',
            ])
            ->getMock();
        $m->method('countActiveForSlot')->willReturn(0);
        $m->method('findActiveByEmailOrUserAndSlot')->willReturn(null);
        $m->method('findOverlappingActiveByEmailOrUser')->willReturn(null);
        $m->method('skipValidation')->willReturnSelf();
        $m->method('insert')->willReturn($returnedId);
        $m->method('stampAdminModification')->willReturn(true);

        return $m;
    }

    private function buildMockSlotModel(): SlotModel
    {
        $m = $this->getMockBuilder(SlotModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findForCapacityCheck'])
            ->getMock();
        $m->method('findForCapacityCheck')->willReturn([
            'id'        => 5,
            'stand_id'  => 1,
            'capacity'  => 10,
            'status'    => 'active',
            'starts_at' => '2026-09-20 09:00:00',
            'ends_at'   => '2026-09-20 12:00:00',
        ]);

        return $m;
    }

    private function buildMockConnection(): BaseConnection
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->method('ignore')->willReturnSelf();
        $builder->method('insert')->willReturn(true);

        $mock = $this->createMock(BaseConnection::class);
        $mock->method('transBegin')->willReturn(true);
        $mock->method('transCommit')->willReturn(true);
        $mock->method('table')->willReturn($builder);
        $mock->method('prefixTable')->willReturnArgument(0);

        return $mock;
    }

    private function buildMockEmailService(bool $sent = true): EmailService
    {
        $m = $this->createMock(EmailService::class);
        $m->method('sendSignupConfirmationEmail')
            ->willReturn(new EmailDeliveryResult($sent, $sent ? null : 'No SMTP in tests'));

        return $m;
    }

    private function makeDto(
        int    $slotId               = 5,
        int    $kermesseId           = 1,
        int    $adminUserId          = 7,
        string $firstName            = 'Alice',
        string $lastName             = 'Martin',
        string $email                = 'alice@test.com',
        string $phone                = '',
        bool   $sendConfirmationEmail = false,
    ): AdminCreateSlotSignupDTO {
        return new AdminCreateSlotSignupDTO(
            slotId:               $slotId,
            kermesseId:           $kermesseId,
            adminUserId:          $adminUserId,
            firstName:            $firstName,
            lastName:             $lastName,
            email:                $email,
            phone:                $phone,
            sendConfirmationEmail: $sendConfirmationEmail,
        );
    }
}
