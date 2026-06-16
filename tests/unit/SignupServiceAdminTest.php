<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use App\Models\KermesseModel;
use App\Models\SignupModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Services\EmailDeliveryResult;
use App\Services\EmailService;
use App\Services\SignupResult;
use App\Services\SignupService;

/**
 * Unit tests for SignupService admin actions — Story 5.10.
 *
 * adminCancelSignup: bypass signups_not_open, optional notification email,
 *   stamps last_modified_by_user_id / last_modified_at.
 * adminEditSignup: writes only to signups table, checks first_access_at lock,
 *   never mutates users table.
 *
 * All DB access is mocked: these are pure unit tests with no DB dependency.
 *
 * @internal
 */
final class SignupServiceAdminTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // adminCancelSignup — bypass signups_not_open
    // ------------------------------------------------------------------

    public function testAdminCancelBypasesSignupsNotOpen(): void
    {
        // Kermesse is CLOSED — volunteer cancel would fail with signups_not_open,
        // but admin cancel must succeed regardless.
        $closedKermesse = ['id' => 1, 'name' => 'K', 'status' => 'closed'];

        $signupRow = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@example.com'];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->with(10, 1)->willReturn($signupRow);
        $signupModel->method('markCancelledByAdmin')->with(10, 7)->willReturn(true);
        $signupModel->method('stampAdminModification')->with(10, 7)->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->with(1)->willReturn($closedKermesse);

        $service = $this->buildService(signupModel: $signupModel, kermesseModel: $kermesseModel);

        $result = $service->adminCancelSignup(signupId: 10, adminUserId: 7, kermesseId: 1, notify: false);

        $this->assertTrue($result->success);
        $this->assertSame(10, $result->signupId);
    }

    public function testAdminCancelReturnsNotFoundWhenSignupMissing(): void
    {
        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->with(99, 1)->willReturn(null);

        $service = $this->buildService(signupModel: $signupModel);

        $result = $service->adminCancelSignup(signupId: 99, adminUserId: 7, kermesseId: 1);

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);
    }

    public function testAdminCancelReturnsCancelFailedWhenMarkFails(): void
    {
        $signupRow = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@example.com'];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->method('markCancelledByAdmin')->willReturn(false);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn(['id' => 1, 'status' => 'open']);

        $service = $this->buildService(signupModel: $signupModel, kermesseModel: $kermesseModel);

        $result = $service->adminCancelSignup(signupId: 10, adminUserId: 7, kermesseId: 1);

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

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->method('markCancelledByAdmin')->willReturn(true);
        $signupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendSignupCancellationEmail')
            ->with('vol@example.com', 'Marie', 'Kermesse Test', 'Pâtisseries (20/06 14h – 16h)')
            ->willReturn(new EmailDeliveryResult(true, null));

        $service = $this->buildService(
            signupModel: $signupModel,
            kermesseModel: $kermesseModel,
            emailService: $emailService,
        );

        $result = $service->adminCancelSignup(signupId: 10, adminUserId: 7, kermesseId: 1, notify: true);

        $this->assertTrue($result->success);
        $this->assertTrue($result->emailSent);
        $this->assertSame('Marie Dupont', $result->context['volunteer_name']);
        $this->assertSame('Pâtisseries (20/06 14h – 16h)', $result->context['slot_label']);
    }

    public function testAdminCancelDoesNotSendEmailWhenNotifyFalse(): void
    {
        $signupRow   = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@example.com', 'first_name' => 'A', 'last_name' => 'B'];
        $kermesseRow = ['id' => 1, 'name' => 'K', 'status' => 'open'];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->method('markCancelledByAdmin')->willReturn(true);
        $signupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendSignupCancellationEmail');

        $service = $this->buildService(
            signupModel: $signupModel,
            kermesseModel: $kermesseModel,
            emailService: $emailService,
        );

        $result = $service->adminCancelSignup(signupId: 10, adminUserId: 7, kermesseId: 1, notify: false);

        $this->assertTrue($result->success);
        $this->assertNull($result->emailSent);
    }

    public function testAdminCancelStampsModificationColumns(): void
    {
        $signupRow   = ['id' => 10, 'user_id' => 99, 'slot_id' => 5, 'email' => 'v@test.com', 'first_name' => 'X', 'last_name' => 'Y'];
        $kermesseRow = ['id' => 1, 'name' => 'K', 'status' => 'preparation'];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->method('markCancelledByAdmin')->willReturn(true);
        // stampAdminModification MUST be called with the admin user id.
        $signupModel->expects($this->once())
            ->method('stampAdminModification')
            ->with(10, 42)
            ->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $service = $this->buildService(signupModel: $signupModel, kermesseModel: $kermesseModel);

        $service->adminCancelSignup(signupId: 10, adminUserId: 42, kermesseId: 1, notify: false);
    }

    // ------------------------------------------------------------------
    // adminEditSignup — edit contact fields on signups table
    // ------------------------------------------------------------------

    public function testAdminEditSucceedsWhenFirstAccessAtIsNull(): void
    {
        $signupRow = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];
        $kurRow = ['first_access_at' => null];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->with(20, 1)->willReturn($signupRow);
        $signupModel->method('updateContactFields')->willReturn(true);
        $signupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $userRoleModel = $this->createMock(UserRoleModel::class);
        $userRoleModel->method('findByKermesseAndUser')->with(1, 55)->willReturn($kurRow);

        $service = $this->buildService(
            signupModel: $signupModel,
            kermesseModel: $kermesseModel,
            userRoleModel: $userRoleModel,
        );

        $result = $service->adminEditSignup(
            signupId: 20,
            adminUserId: 7,
            kermesseId: 1,
            fields: ['first_name' => 'Alice', 'last_name' => 'Martin', 'email' => 'alice@example.com', 'phone' => '0601020304'],
        );

        $this->assertTrue($result->success);
        $this->assertSame(20, $result->signupId);
    }


    public function testAdminEditReturnsNotFoundWhenSignupMissing(): void
    {
        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn(null);

        $service = $this->buildService(signupModel: $signupModel);

        $result = $service->adminEditSignup(
            signupId: 99, adminUserId: 7, kermesseId: 1,
            fields: ['first_name' => 'Test'],
        );

        $this->assertFalse($result->success);
        $this->assertSame('not_found', $result->errorCode);
    }

    public function testAdminEditNeverTouchesUsersTable(): void
    {
        $signupRow   = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];
        $kurRow      = ['first_access_at' => null];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->method('updateContactFields')->willReturn(true);
        $signupModel->method('stampAdminModification')->willReturn(true);

        // userModel must NEVER have update called
        $userModel = $this->createMock(UserModel::class);
        $userModel->expects($this->never())->method('update');
        $userModel->expects($this->never())->method('save');
        $userModel->expects($this->never())->method('insert');

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $userRoleModel = $this->createMock(UserRoleModel::class);
        $userRoleModel->method('findByKermesseAndUser')->willReturn($kurRow);

        $service = $this->buildService(
            userModel: $userModel,
            signupModel: $signupModel,
            kermesseModel: $kermesseModel,
            userRoleModel: $userRoleModel,
        );

        $result = $service->adminEditSignup(
            signupId: 20, adminUserId: 7, kermesseId: 1,
            fields: ['first_name' => 'Alice', 'last_name' => 'B', 'email' => 'alice@test.com', 'phone' => ''],
        );

        $this->assertTrue($result->success);
    }

    public function testAdminEditStampsModificationColumns(): void
    {
        $signupRow   = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];
        $kurRow      = ['first_access_at' => null];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->method('updateContactFields')->willReturn(true);
        $signupModel->expects($this->once())
            ->method('stampAdminModification')
            ->with(20, 33)
            ->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $userRoleModel = $this->createMock(UserRoleModel::class);
        $userRoleModel->method('findByKermesseAndUser')->willReturn($kurRow);

        $service = $this->buildService(
            signupModel: $signupModel,
            kermesseModel: $kermesseModel,
            userRoleModel: $userRoleModel,
        );

        $service->adminEditSignup(
            signupId: 20, adminUserId: 33, kermesseId: 1,
            fields: ['first_name' => 'Alice', 'last_name' => 'B', 'email' => 'a@b.com', 'phone' => ''],
        );
    }

    public function testAdminEditWritesContactFieldsToSignupsTable(): void
    {
        $signupRow   = ['id' => 20, 'user_id' => 55, 'slot_id' => 3, 'email' => 'orig@test.com'];
        $kermesseRow = ['id' => 1, 'status' => 'open'];
        $kurRow      = ['first_access_at' => null];
        $fields      = ['first_name' => 'Alice', 'last_name' => 'Martin', 'email' => 'alice@test.com', 'phone' => '0601020304'];

        $signupModel = $this->createMock(SignupModel::class);
        $signupModel->method('findActiveInKermesse')->willReturn($signupRow);
        $signupModel->expects($this->once())
            ->method('updateContactFields')
            ->with(20, $fields)
            ->willReturn(true);
        $signupModel->method('stampAdminModification')->willReturn(true);

        $kermesseModel = $this->createMock(KermesseModel::class);
        $kermesseModel->method('find')->willReturn($kermesseRow);

        $userRoleModel = $this->createMock(UserRoleModel::class);
        $userRoleModel->method('findByKermesseAndUser')->willReturn($kurRow);

        $service = $this->buildService(
            signupModel: $signupModel,
            kermesseModel: $kermesseModel,
            userRoleModel: $userRoleModel,
        );

        $service->adminEditSignup(signupId: 20, adminUserId: 7, kermesseId: 1, fields: $fields);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildService(
        ?UserModel     $userModel     = null,
        ?SignupModel   $signupModel   = null,
        ?KermesseModel $kermesseModel = null,
        ?EmailService  $emailService  = null,
        ?UserRoleModel $userRoleModel = null,
    ): SignupService {
        return new SignupService(
            userModel:     $userModel  ?? $this->createMock(UserModel::class),
            signupModel:   $signupModel ?? $this->createMock(SignupModel::class),
            kermesseModel: $kermesseModel ?? $this->createMock(KermesseModel::class),
            emailService:  $emailService ?? $this->createMock(EmailService::class),
            userRoleModel: $userRoleModel ?? $this->createMock(UserRoleModel::class),
        );
    }
}
