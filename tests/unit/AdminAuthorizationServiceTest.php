<?php

use App\Models\KermesseModel;
use App\Models\OwnerModel;
use App\Services\AdminAuthorizationService;
use App\Services\AuthorizationResult;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for AdminAuthorizationService.
 *
 * Covers every AuthorizationResult status returned by checkAccess():
 *   - NO_SESSION          : owner_admin_authenticated flag missing/false
 *   - KERMESSE_MISMATCH   : session kermesse_id differs from the URL kermesseId
 *   - PENDING_VALIDATION  : owner exists but still owner_pending
 *   - ACCESS_DENIED       : owner not found / not active / kermesse not owned
 *   - AUTHORIZED          : everything checks out
 *
 * Models are mocked so the suite stays pure-unit (no DB).
 *
 * @internal
 */
final class AdminAuthorizationServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CIUnitTestCase helper: installs MockSession + clears $_SESSION
        $this->mockSession();
    }

    // ------------------------------------------------------------------
    // Mock helpers
    // ------------------------------------------------------------------

    private function buildOwnerModel(?array $owner): OwnerModel
    {
        $mock = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $mock->method('find')->willReturn($owner);

        return $mock;
    }

    private function buildKermesseModel(?array $kermesse): KermesseModel
    {
        $mock = $this->getMockBuilder(KermesseModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $mock->method('where')->willReturnSelf();
        $mock->method('first')->willReturn($kermesse);

        return $mock;
    }

    private function buildService(
        ?OwnerModel $ownerModel = null,
        ?KermesseModel $kermesseModel = null,
    ): AdminAuthorizationService {
        return new AdminAuthorizationService(
            $ownerModel    ?? $this->buildOwnerModel(['id' => 1, 'status' => 'active']),
            $kermesseModel ?? $this->buildKermesseModel(['id' => 2, 'owner_id' => 1]),
        );
    }

    private function seedAuthenticatedSession(int $ownerId = 1, int $kermesseId = 2): void
    {
        session()->set([
            'owner_admin_authenticated' => true,
            'owner_id'                  => $ownerId,
            'kermesse_id'               => $kermesseId,
        ]);
    }

    // ------------------------------------------------------------------
    // NO_SESSION
    // ------------------------------------------------------------------

    public function testEmptySessionReturnsNoSession(): void
    {
        $result = $this->buildService()->checkAccess(2);

        $this->assertSame(AuthorizationResult::NO_SESSION, $result->status);
        $this->assertFalse($result->isAuthorized());
    }

    public function testAdminFlagFalseReturnsNoSession(): void
    {
        session()->set([
            'owner_admin_authenticated' => false,
            'owner_id'                  => 1,
            'kermesse_id'               => 2,
        ]);

        $result = $this->buildService()->checkAccess(2);

        $this->assertSame(AuthorizationResult::NO_SESSION, $result->status);
    }

    public function testAdminFlagNonBooleanTrueIsRejected(): void
    {
        // Strict identity check: only the literal boolean `true` passes
        session()->set([
            'owner_admin_authenticated' => 1, // truthy but not === true
            'owner_id'                  => 1,
            'kermesse_id'               => 2,
        ]);

        $result = $this->buildService()->checkAccess(2);

        $this->assertSame(AuthorizationResult::NO_SESSION, $result->status);
    }

    // ------------------------------------------------------------------
    // KERMESSE_MISMATCH
    // ------------------------------------------------------------------

    public function testSessionKermesseDoesNotMatchUrlReturnsMismatch(): void
    {
        $this->seedAuthenticatedSession(ownerId: 1, kermesseId: 2);

        $result = $this->buildService()->checkAccess(999);

        $this->assertSame(AuthorizationResult::KERMESSE_MISMATCH, $result->status);
        $this->assertFalse($result->isAuthorized());
    }

    // ------------------------------------------------------------------
    // PENDING_VALIDATION
    // ------------------------------------------------------------------

    public function testOwnerPendingValidationReturnsPendingValidation(): void
    {
        $this->seedAuthenticatedSession(1, 2);

        $service = $this->buildService(
            $this->buildOwnerModel(['id' => 1, 'status' => 'owner_pending']),
        );

        $result = $service->checkAccess(2);

        $this->assertSame(AuthorizationResult::PENDING_VALIDATION, $result->status);
        $this->assertFalse($result->isAuthorized());
    }

    // ------------------------------------------------------------------
    // ACCESS_DENIED
    // ------------------------------------------------------------------

    public function testOwnerNotFoundReturnsAccessDenied(): void
    {
        $this->seedAuthenticatedSession(1, 2);

        $service = $this->buildService(
            $this->buildOwnerModel(null),
        );

        $result = $service->checkAccess(2);

        $this->assertSame(AuthorizationResult::ACCESS_DENIED, $result->status);
    }

    public function testOwnerStatusOtherThanActiveReturnsAccessDenied(): void
    {
        $this->seedAuthenticatedSession(1, 2);

        // Any non-`active`, non-`owner_pending` status → access_denied (guards future statuses).
        $service = $this->buildService(
            $this->buildOwnerModel(['id' => 1, 'status' => 'disabled']),
        );

        $result = $service->checkAccess(2);

        $this->assertSame(AuthorizationResult::ACCESS_DENIED, $result->status);
    }

    public function testKermesseNotOwnedByOwnerReturnsAccessDenied(): void
    {
        $this->seedAuthenticatedSession(1, 2);

        $service = $this->buildService(
            $this->buildOwnerModel(['id' => 1, 'status' => 'active']),
            $this->buildKermesseModel(null), // ownership scope check fails
        );

        $result = $service->checkAccess(2);

        $this->assertSame(AuthorizationResult::ACCESS_DENIED, $result->status);
    }

    // ------------------------------------------------------------------
    // AUTHORIZED — happy path
    // ------------------------------------------------------------------

    public function testActiveOwnerWithMatchingKermesseIsAuthorized(): void
    {
        $this->seedAuthenticatedSession(1, 2);

        $result = $this->buildService(
            $this->buildOwnerModel(['id' => 1, 'status' => 'active']),
            $this->buildKermesseModel(['id' => 2, 'owner_id' => 1]),
        )->checkAccess(2);

        $this->assertSame(AuthorizationResult::AUTHORIZED, $result->status);
        $this->assertTrue($result->isAuthorized());
    }

    // ------------------------------------------------------------------
    // Invariant: kermesse ownership is scoped by owner_id
    // ------------------------------------------------------------------

    public function testKermesseLookupIsScopedByOwnerIdNotJustKermesseId(): void
    {
        $this->seedAuthenticatedSession(ownerId: 7, kermesseId: 42);

        // Spy on `where` to confirm both scope clauses are applied.
        $kermesseModel = $this->getMockBuilder(KermesseModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();

        $calls = [];
        $kermesseModel->method('where')->willReturnCallback(
            function ($field, $value) use (&$calls, $kermesseModel) {
                $calls[] = [$field, $value];
                return $kermesseModel;
            }
        );
        $kermesseModel->method('first')->willReturn(['id' => 42, 'owner_id' => 7]);

        $result = $this->buildService(
            $this->buildOwnerModel(['id' => 7, 'status' => 'active']),
            $kermesseModel,
        )->checkAccess(42);

        $this->assertSame(AuthorizationResult::AUTHORIZED, $result->status);
        $this->assertContains(['id', 42], $calls, 'Kermesse lookup must filter by id');
        $this->assertContains(['owner_id', 7], $calls, 'Kermesse lookup must filter by owner_id');
    }
}
