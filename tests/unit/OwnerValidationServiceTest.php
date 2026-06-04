<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\OwnerValidationService;
use App\Services\ValidationOutcome;
use App\Services\TokenService;
use App\Services\TokenValidationResult;
use App\Models\KermesseModel;
use App\Models\OwnerModel;

/**
 * Unit tests for OwnerValidationService.
 *
 * All external dependencies are mocked; no DB required.
 *
 * @internal
 */
final class OwnerValidationServiceTest extends CIUnitTestCase
{
    private function buildService(
        ?TokenService $tokenService = null,
        ?OwnerModel   $ownerModel   = null,
        ?KermesseModel $kermesseModel = null,
    ): OwnerValidationService {
        return new OwnerValidationService($tokenService, $ownerModel, $kermesseModel ?? $this->buildKermesseModelMock());
    }

    private function buildKermesseModelMock(?array $row = ['id' => 5, 'owner_id' => 1]): KermesseModel
    {
        $mock = $this->getMockBuilder(KermesseModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $mock->method('where')->willReturnSelf();
        $mock->method('first')->willReturn($row);
        return $mock;
    }

    private function pendingOwnerRow(int $id = 1): array
    {
        return ['id' => $id, 'status' => 'owner_pending', 'email' => 'test@example.com'];
    }

    private function activeOwnerRow(int $id = 1): array
    {
        return ['id' => $id, 'status' => 'active', 'email' => 'test@example.com'];
    }

    private function validTokenRow(int $ownerId = 1, int $kermesseId = 5, int $tokenId = 99): array
    {
        return [
            'id'          => $tokenId,
            'owner_id'    => $ownerId,
            'kermesse_id' => $kermesseId,
            'token_type'  => 'owner_validation',
            'used_at'     => null,
            'revoked_at'  => null,
            'expires_at'  => date('Y-m-d H:i:s', time() + 3600),
        ];
    }

    // ------------------------------------------------------------------
    // Invalid / unknown token
    // ------------------------------------------------------------------

    public function testInvalidTokenReturnsInvalidOutcome(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::INVALID_TOKEN));

        $outcome = $this->buildService($tokenService)->processValidation('bad-token');

        $this->assertSame(ValidationOutcome::INVALID_TOKEN, $outcome->status);
        $this->assertFalse($outcome->isSuccess());
    }

    public function testUsedTokenReturnsInvalidOutcome(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(
                         TokenValidationResult::USED_TOKEN,
                         ['owner_id' => 1, 'kermesse_id' => 5],
                     ));

        $outcome = $this->buildService($tokenService)->processValidation('used-token');

        $this->assertSame(ValidationOutcome::USED_TOKEN, $outcome->status);
    }

    public function testRevokedTokenReturnsInvalidOutcome(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(
                         TokenValidationResult::REVOKED_TOKEN,
                         ['owner_id' => 1, 'kermesse_id' => 5],
                     ));

        $outcome = $this->buildService($tokenService)->processValidation('revoked-token');

        $this->assertSame(ValidationOutcome::REVOKED_TOKEN, $outcome->status);
    }

    // ------------------------------------------------------------------
    // Expired token
    // ------------------------------------------------------------------

    public function testExpiredTokenWithPendingOwnerReturnsExpiredPending(): void
    {
        $tokenRow = ['owner_id' => 1, 'kermesse_id' => 5, 'expires_at' => '2020-01-01 00:00:00'];

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(
                         TokenValidationResult::EXPIRED_TOKEN,
                         $tokenRow,
                     ));

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->pendingOwnerRow());

        $outcome = $this->buildService($tokenService, $ownerModel)->processValidation('expired-token');

        $this->assertSame(ValidationOutcome::EXPIRED_PENDING, $outcome->status);
    }

    public function testExpiredTokenWithActiveOwnerReturnsInvalid(): void
    {
        $tokenRow = ['owner_id' => 1, 'kermesse_id' => 5, 'expires_at' => '2020-01-01 00:00:00'];

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(
                         TokenValidationResult::EXPIRED_TOKEN,
                         $tokenRow,
                     ));

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->activeOwnerRow());

        $outcome = $this->buildService($tokenService, $ownerModel)->processValidation('expired-token');

        $this->assertSame(ValidationOutcome::INVALID_TOKEN, $outcome->status);
    }

    // ------------------------------------------------------------------
    // Already-active owner
    // ------------------------------------------------------------------

    public function testAlreadyActiveOwnerReturnsAlreadyActiveOutcome(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(
                         TokenValidationResult::VALID,
                         $this->validTokenRow(),
                     ));

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->activeOwnerRow());

        $outcome = $this->buildService($tokenService, $ownerModel)->processValidation('any-token');

        $this->assertSame(ValidationOutcome::ALREADY_ACTIVE, $outcome->status);
        $this->assertFalse($outcome->isSuccess());
    }

    // ------------------------------------------------------------------
    // Successful validation
    // ------------------------------------------------------------------

    public function testValidTokenWithPendingOwnerReturnsSuccess(): void
    {
        $tokenRow = $this->validTokenRow(ownerId: 2, kermesseId: 7, tokenId: 55);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::VALID, $tokenRow));
        $tokenService->expects($this->once())->method('markTokenAsUsed')->with(55)->willReturn(true);

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->pendingOwnerRow(2));
        $ownerModel->method('skipValidation')->willReturnSelf();
        $ownerModel->expects($this->once())->method('update')->with(
            2,
            $this->arrayHasKey('status'),
        )->willReturn(true);

        $outcome = $this->buildService($tokenService, $ownerModel)->processValidation('valid-token');

        $this->assertTrue($outcome->isSuccess());
        $this->assertSame(ValidationOutcome::SUCCESS, $outcome->status);
        $this->assertSame(2, $outcome->ownerId);
        $this->assertSame(7, $outcome->kermesseId);
    }

    public function testValidTokenWithKermesseNotOwnedByOwnerReturnsInvalid(): void
    {
        $tokenRow = $this->validTokenRow(ownerId: 2, kermesseId: 7, tokenId: 55);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::VALID, $tokenRow));
        $tokenService->expects($this->never())->method('markTokenAsUsed');

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->pendingOwnerRow(2));

        $outcome = $this->buildService(
            $tokenService,
            $ownerModel,
            $this->buildKermesseModelMock(null),
        )->processValidation('valid-token');

        $this->assertSame(ValidationOutcome::INVALID_TOKEN, $outcome->status);
    }

    public function testUsedAndRevokedTokensReturnDistinctOutcomes(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturnOnConsecutiveCalls(
                         new TokenValidationResult(TokenValidationResult::USED_TOKEN, ['owner_id' => 1]),
                         new TokenValidationResult(TokenValidationResult::REVOKED_TOKEN, ['owner_id' => 1]),
                     );

        $service = $this->buildService($tokenService);

        $this->assertSame(ValidationOutcome::USED_TOKEN, $service->processValidation('used')->status);
        $this->assertSame(ValidationOutcome::REVOKED_TOKEN, $service->processValidation('revoked')->status);
    }

    public function testValidTokenWithMissingScopeReturnsInvalid(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(
                         TokenValidationResult::VALID,
                         ['id' => 55, 'owner_id' => null, 'kermesse_id' => null],
                     ));
        $tokenService->expects($this->never())->method('markTokenAsUsed');

        $outcome = $this->buildService($tokenService)->processValidation('valid-token');

        $this->assertSame(ValidationOutcome::INVALID_TOKEN, $outcome->status);
    }

    public function testConcurrentTokenUseReturnsErrorWhenMarkUsedFails(): void
    {
        $tokenRow = $this->validTokenRow(ownerId: 2, kermesseId: 7, tokenId: 55);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::VALID, $tokenRow));
        $tokenService->method('markTokenAsUsed')->willReturn(false);

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->pendingOwnerRow(2));
        $ownerModel->method('skipValidation')->willReturnSelf();
        $ownerModel->method('update')->willReturn(true);

        $outcome = $this->buildService($tokenService, $ownerModel)->processValidation('valid-token');

        $this->assertSame(ValidationOutcome::ERROR, $outcome->status);
    }

    public function testOwnerUpdateNotCalledWhenMarkTokenUsedFails(): void
    {
        $tokenRow = $this->validTokenRow(ownerId: 2, kermesseId: 7, tokenId: 55);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::VALID, $tokenRow));
        $tokenService->method('markTokenAsUsed')->willReturn(false);

        $ownerModel = $this->createMock(OwnerModel::class);
        $ownerModel->method('find')->willReturn($this->pendingOwnerRow(2));
        $ownerModel->method('skipValidation')->willReturnSelf();
        $ownerModel->expects($this->never())->method('update');

        $outcome = $this->buildService($tokenService, $ownerModel)->processValidation('valid-token');

        $this->assertSame(ValidationOutcome::ERROR, $outcome->status);
    }

    // ------------------------------------------------------------------
    // Session must not contain raw token
    // ------------------------------------------------------------------

    public function testProcessValidationNeverReturnsRawToken(): void
    {
        $rawToken = 'super-secret-raw-token-xyz';

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateOwnerToken')
                     ->willReturn(new TokenValidationResult(TokenValidationResult::INVALID_TOKEN));

        $outcome = $this->buildService($tokenService)->processValidation($rawToken);

        // Outcome must not leak the raw token in any public field
        $serialized = json_encode($outcome);
        $this->assertStringNotContainsString($rawToken, $serialized);
    }
}
