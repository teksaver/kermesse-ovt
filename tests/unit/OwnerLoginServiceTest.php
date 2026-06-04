<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\OwnerLoginService;
use App\Services\LoginRequestResult;
use App\Services\TokenService;
use App\Services\EmailService;
use App\Services\IssuedToken;
use App\Services\EmailDeliveryResult;
use App\Models\KermesseModel;
use App\Models\OwnerModel;

/**
 * Unit tests for OwnerLoginService.
 *
 * @internal
 */
final class OwnerLoginServiceTest extends CIUnitTestCase
{
    private function buildService(
        ?OwnerModel    $ownerModel    = null,
        ?TokenService  $tokenService  = null,
        ?EmailService  $emailService  = null,
        ?KermesseModel $kermesseModel = null,
    ): OwnerLoginService {
        return new OwnerLoginService($ownerModel, $tokenService, $emailService, $kermesseModel);
    }

    private function buildMockKermesseModel(?array $kermesseRow = null): KermesseModel
    {
        $mock = $this->getMockBuilder(KermesseModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $mock->method('where')->willReturnSelf();
        $mock->method('first')->willReturn($kermesseRow);
        return $mock;
    }

    private function buildMockTokenService(string $rawToken = 'fake-token'): TokenService
    {
        $mock = $this->createMock(TokenService::class);
        $mock->method('issueOwnerValidationToken')
             ->willReturn(new IssuedToken($rawToken, 1));
        $mock->method('revokeActiveOwnerValidationTokens');
        $mock->method('revokeOlderActiveOwnerValidationTokens');
        $mock->method('hasRecentActiveOwnerValidationToken')->willReturn(false);
        return $mock;
    }

    private function buildMockEmailService(bool $sent = false): EmailService
    {
        $mock = $this->createMock(EmailService::class);
        $mock->method('sendOwnerValidationEmail')
             ->willReturn(new EmailDeliveryResult($sent, $sent ? null : 'No SMTP'));
        return $mock;
    }

    // ------------------------------------------------------------------

    private function buildOwnerModelMock(?array $returnFirstValue = null): OwnerModel
    {
        $mock = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $mock->method('where')->willReturnSelf();
        $mock->method('first')->willReturn($returnFirstValue);
        return $mock;
    }

    public function testUnknownEmailReturnsNeutralCheckEmail(): void
    {
        $ownerModel = $this->buildOwnerModelMock(null);

        $result = $this->buildService($ownerModel)->requestOwnerLink('unknown@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testActiveOwnerReturnsNeutralCheckEmailWithoutToken(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'     => 3,
            'status' => 'active',
            'email'  => 'active@example.com',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');

        $result = $this->buildService($ownerModel, $tokenService)->requestOwnerLink('active@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testPendingOwnerRevokesTokensAndIssuesNew(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 7,
            'status'       => 'owner_pending',
            'email'        => 'pending@example.com',
            'display_name' => 'Jean',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 3,
            'name' => 'Kermesse Jean',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerValidationToken')->willReturn(false);
        // All active tokens are revoked atomically before issuing the new one
        $tokenService->expects($this->once())
                     ->method('revokeActiveOwnerValidationTokens')
                     ->with(7);
        $tokenService->expects($this->once())
                     ->method('issueOwnerValidationToken')
                     ->with(7, 3, 'pending@example.com')
                     ->willReturn(new IssuedToken('new-token', 42));
        $tokenService->expects($this->never())->method('revokeToken');
        $tokenService->expects($this->never())->method('revokeOlderActiveOwnerValidationTokens');

        $emailService = $this->buildMockEmailService(sent: true);

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testFailedEmailRevokesOnlyNewToken(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 7,
            'status'       => 'owner_pending',
            'email'        => 'pending@example.com',
            'display_name' => 'Jean',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 3,
            'name' => 'Kermesse Jean',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerValidationToken')->willReturn(false);
        $tokenService->method('revokeActiveOwnerValidationTokens');
        $tokenService->method('issueOwnerValidationToken')->willReturn(new IssuedToken('new-token', 42));
        $tokenService->expects($this->once())->method('revokeToken')->with(42);
        $tokenService->expects($this->never())->method('revokeOlderActiveOwnerValidationTokens');

        $result = $this->buildService($ownerModel, $tokenService, $this->buildMockEmailService(), $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testEmailNormalisedBeforeHashLookup(): void
    {
        $capturedHash = null;

        $ownerModel = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $ownerModel->method('where')->willReturnCallback(
            function (string $field, $value) use (&$capturedHash, $ownerModel) {
                if ($field === 'email_hash') {
                    $capturedHash = $value;
                }
                return $ownerModel;
            }
        );
        $ownerModel->method('first')->willReturn(null);

        $this->buildService($ownerModel)->requestOwnerLink('  OWNER@Example.COM  ');

        $expectedHash = hash('sha256', 'owner@example.com');
        $this->assertSame($expectedHash, $capturedHash,
            'Email must be normalised (trim + strtolower) before hashing');
    }

    public function testResultNeverExposesRawToken(): void
    {
        $rawToken = 'very-secret-raw-token';

        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'owner_pending',
            'email'        => 'test@example.com',
            'display_name' => 'Test',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 2,
            'name' => 'Kermesse Test',
        ]);
        $tokenService  = $this->buildMockTokenService($rawToken);
        $emailService  = $this->buildMockEmailService();

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('test@example.com');

        // The raw token must never appear in the result
        $serialized = json_encode($result);
        $this->assertStringNotContainsString($rawToken, $serialized);
    }

    public function testPendingOwnerWithoutKermesseDoesNotIssueToken(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'owner_pending',
            'email'        => 'test@example.com',
            'display_name' => 'Test',
        ]);

        $kermesseModel = $this->buildMockKermesseModel(null);
        $tokenService  = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');

        $result = $this->buildService($ownerModel, $tokenService, null, $kermesseModel)
                       ->requestOwnerLink('test@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testRecentActiveValidationTokenSkipsTokenChurn(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'owner_pending',
            'email'        => 'test@example.com',
            'display_name' => 'Test',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 2,
            'name' => 'Kermesse Test',
        ]);
        // Cooldown is now based on an active token in DB, not email_events
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerValidationToken')->willReturn(true);
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendOwnerValidationEmail');

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('test@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }
}
