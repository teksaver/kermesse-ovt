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

    // ------------------------------------------------------------------
    // Active owner branch (Story 1.6)
    // ------------------------------------------------------------------

    public function testActiveOwnerWithMissingKermesseReturnsNeutralWithoutLoginToken(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 3,
            'status'       => 'active',
            'email'        => 'active@example.com',
            'display_name' => 'Active Owner',
        ]);

        $kermesseModel = $this->buildMockKermesseModel(null);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issueOwnerLoginToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerLoginTokens');
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');

        $result = $this->buildService($ownerModel, $tokenService, null, $kermesseModel)
                       ->requestOwnerLink('active@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testActiveOwnerIssuedLoginTokenAndReturnsNeutral(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 3,
            'status'       => 'active',
            'email'        => 'active@example.com',
            'display_name' => 'Active Owner',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 5,
            'name' => 'Kermesse Active',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerLoginToken')->willReturn(false);
        // On email success: revoke older tokens, preserving the newly issued one (exceptTokenId = 7).
        $tokenService->expects($this->once())
                     ->method('revokeActiveOwnerLoginTokens')
                     ->with(3, 7);
        $tokenService->expects($this->once())
                     ->method('issueOwnerLoginToken')
                     ->with(3, 5, 'active@example.com')
                     ->willReturn(new IssuedToken('login-token', 7));
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
                     ->method('sendOwnerLoginEmail')
                     ->willReturn(new EmailDeliveryResult(true));
        $emailService->expects($this->never())->method('sendOwnerValidationEmail');

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('active@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testActiveOwnerWithLoginCooldownSkipsEmission(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 3,
            'status'       => 'active',
            'email'        => 'active@example.com',
            'display_name' => 'Active Owner',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 5,
            'name' => 'Kermesse Active',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerLoginToken')->willReturn(true);
        $tokenService->expects($this->never())->method('issueOwnerLoginToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerLoginTokens');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendOwnerLoginEmail');

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('active@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testActiveOwnerFailedEmailRevokesLoginToken(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 3,
            'status'       => 'active',
            'email'        => 'active@example.com',
            'display_name' => 'Active Owner',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 5,
            'name' => 'Kermesse Active',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerLoginToken')->willReturn(false);
        $tokenService->method('issueOwnerLoginToken')->willReturn(new IssuedToken('login-token', 7));
        // On email failure: revoke only the new (undelivered) token; old links stay usable.
        $tokenService->expects($this->never())->method('revokeActiveOwnerLoginTokens');
        $tokenService->expects($this->once())->method('revokeLoginToken')->with(7);

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOwnerLoginEmail')->willReturn(new EmailDeliveryResult(false, 'No SMTP'));

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('active@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testActiveOwnerLoginResultNeverExposesRawToken(): void
    {
        $rawToken = 'very-secret-login-raw-token';

        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'active',
            'email'        => 'active@example.com',
            'display_name' => 'Active Owner',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 2,
            'name' => 'Kermesse Test',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerLoginToken')->willReturn(false);
        $tokenService->method('issueOwnerLoginToken')->willReturn(new IssuedToken($rawToken, 1));
        $tokenService->method('revokeLoginToken');

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOwnerLoginEmail')->willReturn(new EmailDeliveryResult(false));

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('active@example.com');

        $serialized = json_encode($result);
        $this->assertStringNotContainsString($rawToken, $serialized);
    }

    // ------------------------------------------------------------------
    // owner_pending branch must never create owner_login tokens
    // ------------------------------------------------------------------

    public function testPendingOwnerNeverReceivesOwnerLoginToken(): void
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
        $tokenService->method('issueOwnerValidationToken')->willReturn(new IssuedToken('val-token', 1));
        $tokenService->expects($this->never())->method('issueOwnerLoginToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerLoginTokens');

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOwnerValidationEmail')->willReturn(new EmailDeliveryResult(true));

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    // ------------------------------------------------------------------
    // owner_pending branch (Story 1.5 — preserved)
    // ------------------------------------------------------------------

    public function testPendingOwnerIssuesTokenThenRevokesOlderOnEmailSuccess(): void
    {
        // New flow: issue first, revoke older links only AFTER email is delivered.
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
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');
        $tokenService->expects($this->once())
                     ->method('issueOwnerValidationToken')
                     ->with(7, 3, 'pending@example.com')
                     ->willReturn(new IssuedToken('new-token', 42));
        $tokenService->expects($this->never())->method('revokeToken');
        $tokenService->expects($this->once())
                     ->method('revokeOlderActiveOwnerValidationTokens')
                     ->with(7, 42);

        $emailService = $this->buildMockEmailService(sent: true);

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testFailedEmailRevokesOnlyNewTokenAndPreservesOldLinks(): void
    {
        // When email fails: revoke the undelivered new token, leave old links intact.
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
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');
        $tokenService->method('issueOwnerValidationToken')->willReturn(new IssuedToken('new-token', 42));
        $tokenService->expects($this->once())->method('revokeToken')->with(42);
        $tokenService->expects($this->never())->method('revokeOlderActiveOwnerValidationTokens');

        $result = $this->buildService($ownerModel, $tokenService, $this->buildMockEmailService(), $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testResendWithOldValidLinkEmailFailurePreservesOldToken(): void
    {
        // Scenario: owner has an old usable link (outside cooldown). Resend is attempted,
        // but email delivery fails. The old link must remain untouched so the user can still use it.
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'owner_pending',
            'email'        => 'pending@example.com',
            'display_name' => 'Jean',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 4,
            'name' => 'Kermesse Jean',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('hasRecentActiveOwnerValidationToken')->willReturn(false);
        $tokenService->method('issueOwnerValidationToken')->willReturn(new IssuedToken('new-token', 77));

        // Old token (outside cooldown window) must NOT be revoked when email fails
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');
        $tokenService->expects($this->never())->method('revokeOlderActiveOwnerValidationTokens');
        // Only the new (undelivered) token is revoked
        $tokenService->expects($this->once())->method('revokeToken')->with(77);

        $result = $this->buildService($ownerModel, $tokenService, $this->buildMockEmailService(sent: false), $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testConcurrentResendBlockedByCooldownPreventsInvalidatingLinks(): void
    {
        // If a token was recently issued (within cooldown window), a second request must be
        // blocked — preventing two concurrent requests from each revoking the other's token.
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'owner_pending',
            'email'        => 'pending@example.com',
            'display_name' => 'Jean',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 4,
            'name' => 'Kermesse Jean',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        // Cooldown: a recent active token already exists (e.g., issued by concurrent request)
        $tokenService->method('hasRecentActiveOwnerValidationToken')->willReturn(true);

        // No token should be issued or revoked
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');
        $tokenService->expects($this->never())->method('revokeToken');
        $tokenService->expects($this->never())->method('revokeActiveOwnerValidationTokens');
        $tokenService->expects($this->never())->method('revokeOlderActiveOwnerValidationTokens');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendOwnerValidationEmail');

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
                       ->requestOwnerLink('pending@example.com');

        $this->assertSame(LoginRequestResult::CHECK_EMAIL, $result->status);
    }

    public function testPendingOwnerRechecksCooldownInsideSerializedResendSection(): void
    {
        $ownerModel = $this->buildOwnerModelMock([
            'id'           => 9,
            'status'       => 'owner_pending',
            'email'        => 'pending@example.com',
            'display_name' => 'Jean',
        ]);

        $kermesseModel = $this->buildMockKermesseModel([
            'id'   => 4,
            'name' => 'Kermesse Jean',
        ]);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->exactly(2))
                     ->method('hasRecentActiveOwnerValidationToken')
                     ->with(9, 300)
                     ->willReturnOnConsecutiveCalls(false, true);
        $tokenService->expects($this->never())->method('issueOwnerValidationToken');
        $tokenService->expects($this->never())->method('revokeToken');
        $tokenService->expects($this->never())->method('revokeOlderActiveOwnerValidationTokens');

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendOwnerValidationEmail');

        $result = $this->buildService($ownerModel, $tokenService, $emailService, $kermesseModel)
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
