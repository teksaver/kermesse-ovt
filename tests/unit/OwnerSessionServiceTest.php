<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\OwnerSessionService;
use App\Services\SessionOutcome;
use App\Services\TokenService;
use App\Services\TokenValidationResult;
use App\Models\KermesseModel;
use App\Models\OwnerModel;

/**
 * Unit tests for OwnerSessionService.
 *
 * @internal
 */
final class OwnerSessionServiceTest extends CIUnitTestCase
{
    private function buildValidTokenResult(int $tokenId = 10, int $ownerId = 1, int $kermesseId = 2): TokenValidationResult
    {
        return new TokenValidationResult(TokenValidationResult::VALID, [
            'id'          => $tokenId,
            'owner_id'    => $ownerId,
            'kermesse_id' => $kermesseId,
            'token_type'  => 'owner_login',
            'used_at'     => null,
            'revoked_at'  => null,
            'expires_at'  => date('Y-m-d H:i:s', time() + 900),
        ]);
    }

    private function buildMockOwnerModel(?array $owner = null): OwnerModel
    {
        $mock = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $mock->method('find')->willReturn($owner);
        return $mock;
    }

    private function buildMockKermesseModel(?array $kermesse = null): KermesseModel
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
        TokenService $tokenService,
        ?OwnerModel $ownerModel = null,
        ?KermesseModel $kermesseModel = null,
    ): OwnerSessionService {
        return new OwnerSessionService(
            $tokenService,
            $ownerModel ?? $this->buildMockOwnerModel(['id' => 1, 'status' => 'active']),
            $kermesseModel ?? $this->buildMockKermesseModel(['id' => 2, 'owner_id' => 1]),
        );
    }

    private function buildValidatingTokenService(TokenValidationResult $result, bool $markUsed = true): TokenService
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerLoginToken')->willReturn($result);
        $tokenService->method('validateOwnerLoginTokenById')->willReturn($result);
        $tokenService->method('markLoginTokenAsUsed')->willReturn($markUsed);

        return $tokenService;
    }

    // ------------------------------------------------------------------
    // Invalid token statuses — no DB or owner/kermesse checks needed
    // ------------------------------------------------------------------

    public function testInvalidTokenReturnsInvalidToken(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerLoginToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::INVALID_TOKEN));

        $outcome = $this->buildService($tokenService)->consumeLoginToken('bad-token');

        $this->assertSame(SessionOutcome::INVALID_TOKEN, $outcome->status);
        $this->assertFalse($outcome->isSuccess());
        $this->assertNull($outcome->ownerId);
        $this->assertNull($outcome->kermesseId);
    }

    public function testExpiredTokenReturnsExpiredToken(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerLoginToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::EXPIRED_TOKEN, [
                         'id' => 1, 'owner_id' => 1, 'kermesse_id' => 2,
                         'expires_at' => '2020-01-01 00:00:00',
                         'used_at' => null, 'revoked_at' => null,
                     ]));

        $outcome = $this->buildService($tokenService)->consumeLoginToken('expired-token');

        $this->assertSame(SessionOutcome::EXPIRED_TOKEN, $outcome->status);
        $this->assertFalse($outcome->isSuccess());
    }

    public function testUsedTokenReturnsUsedToken(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerLoginToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::USED_TOKEN, [
                         'id' => 1, 'owner_id' => 1, 'kermesse_id' => 2,
                         'used_at' => '2026-06-01 12:00:00',
                         'revoked_at' => null, 'expires_at' => date('Y-m-d H:i:s', time() + 900),
                     ]));

        $outcome = $this->buildService($tokenService)->consumeLoginToken('used-token');

        $this->assertSame(SessionOutcome::USED_TOKEN, $outcome->status);
    }

    public function testRevokedTokenReturnsRevokedToken(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerLoginToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::REVOKED_TOKEN, [
                         'id' => 1, 'owner_id' => 1, 'kermesse_id' => 2,
                         'revoked_at' => '2026-06-01 12:00:00',
                         'used_at' => null, 'expires_at' => date('Y-m-d H:i:s', time() + 900),
                     ]));

        $outcome = $this->buildService($tokenService)->consumeLoginToken('revoked-token');

        $this->assertSame(SessionOutcome::REVOKED_TOKEN, $outcome->status);
    }

    // ------------------------------------------------------------------
    // Owner and kermesse scope checks
    // ------------------------------------------------------------------

    public function testOwnerNotFoundReturnsInvalidToken(): void
    {
        $tokenService = $this->buildValidatingTokenService($this->buildValidTokenResult(10, 1, 2));

        $ownerModel = $this->buildMockOwnerModel(null);

        $outcome = $this->buildService($tokenService, $ownerModel)->consumeLoginToken('valid-token');

        $this->assertSame(SessionOutcome::INVALID_TOKEN, $outcome->status);
    }

    public function testOwnerPendingReturnsInvalidToken(): void
    {
        $tokenService = $this->buildValidatingTokenService($this->buildValidTokenResult(10, 1, 2));

        $ownerModel = $this->buildMockOwnerModel(['id' => 1, 'status' => 'owner_pending']);

        $outcome = $this->buildService($tokenService, $ownerModel)->consumeLoginToken('valid-token');

        $this->assertSame(SessionOutcome::INVALID_TOKEN, $outcome->status);
    }

    public function testKermesseNotBelongingToOwnerReturnsInvalidToken(): void
    {
        $tokenService = $this->buildValidatingTokenService($this->buildValidTokenResult(10, 1, 2));

        $ownerModel    = $this->buildMockOwnerModel(['id' => 1, 'status' => 'active']);
        $kermesseModel = $this->buildMockKermesseModel(null); // ownership check fails

        $outcome = $this->buildService($tokenService, $ownerModel, $kermesseModel)
                        ->consumeLoginToken('valid-token');

        $this->assertSame(SessionOutcome::INVALID_TOKEN, $outcome->status);
    }

    // ------------------------------------------------------------------
    // Success path
    // ------------------------------------------------------------------

    public function testValidTokenActiveOwnerCorrectKermesseReturnsSuccess(): void
    {
        $tokenService = $this->buildValidatingTokenService($this->buildValidTokenResult(10, 1, 2));

        $ownerModel    = $this->buildMockOwnerModel(['id' => 1, 'status' => 'active']);
        $kermesseModel = $this->buildMockKermesseModel(['id' => 2, 'owner_id' => 1]);

        $service = new OwnerSessionService($tokenService, $ownerModel, $kermesseModel);
        $outcome = $service->consumeLoginToken('valid-token');

        $this->assertSame(SessionOutcome::SUCCESS, $outcome->status);
        $this->assertTrue($outcome->isSuccess());
        $this->assertSame(1, $outcome->ownerId);
        $this->assertSame(2, $outcome->kermesseId);
    }

    public function testMarkTokenAsUsedFailureReturnsError(): void
    {
        $tokenService = $this->buildValidatingTokenService($this->buildValidTokenResult(10, 1, 2), false);

        $ownerModel    = $this->buildMockOwnerModel(['id' => 1, 'status' => 'active']);
        $kermesseModel = $this->buildMockKermesseModel(['id' => 2, 'owner_id' => 1]);

        $service = new OwnerSessionService($tokenService, $ownerModel, $kermesseModel);
        $outcome = $service->consumeLoginToken('valid-token');

        $this->assertSame(SessionOutcome::ERROR, $outcome->status);
        $this->assertFalse($outcome->isSuccess());
    }

    // ------------------------------------------------------------------
    // Invariant: owners.status must never be modified
    // ------------------------------------------------------------------

    public function testConsumeLoginTokenNeverModifiesOwnerStatus(): void
    {
        $capturedArgs = [];

        $ownerModel = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'update', 'save'])
            ->getMock();
        $ownerModel->method('find')->willReturn(['id' => 1, 'status' => 'active']);
        $ownerModel->expects($this->never())->method('update');
        $ownerModel->expects($this->never())->method('save');

        $tokenService = $this->buildValidatingTokenService($this->buildValidTokenResult(10, 1, 2));

        $kermesseModel = $this->buildMockKermesseModel(['id' => 2, 'owner_id' => 1]);

        $service = new OwnerSessionService($tokenService, $ownerModel, $kermesseModel);
        $outcome = $service->consumeLoginToken('valid-token');

        $this->assertSame(SessionOutcome::SUCCESS, $outcome->status);
    }

    public function testPrevalidateReturnsTokenIdWithoutConsuming(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerLoginToken')
                     ->willReturn($this->buildValidTokenResult(44, 1, 2));
        $tokenService->expects($this->never())->method('markLoginTokenAsUsed');

        $service = $this->buildService($tokenService);
        $outcome = $service->prevalidateLoginToken('valid-token');

        $this->assertSame(SessionOutcome::SUCCESS, $outcome->status);
        $this->assertSame(44, $outcome->tokenId);
    }

    public function testConsumePrevalidatedLoginTokenUsesTokenIdLookup(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('validateOwnerLoginToken');
        $tokenService->expects($this->once())
                     ->method('validateOwnerLoginTokenById')
                     ->with(44)
                     ->willReturn($this->buildValidTokenResult(44, 1, 2));
        $tokenService->method('markLoginTokenAsUsed')->willReturn(true);

        $service = $this->buildService($tokenService);
        $outcome = $service->consumePrevalidatedLoginToken(44);

        $this->assertSame(SessionOutcome::SUCCESS, $outcome->status);
    }
}
