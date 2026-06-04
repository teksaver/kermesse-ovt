<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\KermesseCreationService;
use App\Services\CreationResult;
use App\Services\TokenService;
use App\Services\EmailService;
use App\Services\IssuedToken;
use App\Services\EmailDeliveryResult;
use App\Models\OwnerModel;
use App\Models\KermesseModel;

/**
 * Unit tests for KermesseCreationService orchestration.
 *
 * Uses mocks for all external dependencies to keep tests fast and isolated.
 *
 * @internal
 */
final class KermesseCreationServiceTest extends CIUnitTestCase
{
    private function buildService(
        ?OwnerModel    $ownerModel    = null,
        ?KermesseModel $kermesseModel = null,
        ?TokenService  $tokenService  = null,
        ?EmailService  $emailService  = null,
    ): KermesseCreationService {
        return new KermesseCreationService(
            $ownerModel    ?? $this->buildMockOwnerModel(),
            $kermesseModel ?? $this->buildMockKermesseModel(),
            $tokenService  ?? $this->buildMockTokenService(),
            $emailService  ?? $this->buildMockEmailService(),
        );
    }

    private function buildMockOwnerModel(): OwnerModel
    {
        // 'where' is a builder proxy via __call — must be added via addMethods
        $mock = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first', 'skipValidation', 'insert'])
            ->getMock();
        $mock->method('where')->willReturnSelf();
        $mock->method('first')->willReturn(null); // no duplicate
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn(1);
        return $mock;
    }

    private function buildMockKermesseModel(): KermesseModel
    {
        $mock = $this->createMock(KermesseModel::class);
        $mock->method('skipValidation')->willReturnSelf();
        $mock->method('insert')->willReturn(10);
        return $mock;
    }

    private function buildMockTokenService(): TokenService
    {
        $mock = $this->createMock(TokenService::class);
        $mock->method('issueOwnerValidationToken')
             ->willReturn(new IssuedToken('raw-fake-token', 99));
        return $mock;
    }

    private function buildMockEmailService(): EmailService
    {
        $mock = $this->createMock(EmailService::class);
        $mock->method('sendOwnerValidationEmail')
             ->willReturn(new EmailDeliveryResult(false, 'No SMTP in tests'));
        return $mock;
    }

    private function validInput(): array
    {
        return [
            'owner_name'        => 'Jean Dupont',
            'owner_email'       => 'jean@example.com',
            'kermesse_name'     => 'Kermesse Test',
            'event_date'        => '2026-09-20',
            'location'          => 'Paris',
            'short_description' => 'Une kermesse de test.',
        ];
    }

    // ------------------------------------------------------------------

    public function testSuccessfulCreationReturnsSuccessResult(): void
    {
        $service = $this->buildService();
        $result  = $service->createWithPendingOwner($this->validInput());

        $this->assertInstanceOf(CreationResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(1, $result->ownerId);
        $this->assertSame(10, $result->kermesseId);
        $this->assertNull($result->errorCode);
    }

    public function testRawTokenIsNotExposedInCreationResult(): void
    {
        $service = $this->buildService();
        $result  = $service->createWithPendingOwner($this->validInput());

        // The raw token must never appear anywhere in the result object
        $this->assertStringNotContainsString('raw-fake-token', json_encode($result),
            'Raw token must not be exposed in the creation result');
    }

    public function testDuplicateEmailReturnsDuplicateErrorCode(): void
    {
        $ownerMock = $this->getMockBuilder(OwnerModel::class)
            ->disableOriginalConstructor()
            ->addMethods(['where'])
            ->onlyMethods(['first'])
            ->getMock();
        $ownerMock->method('where')->willReturnSelf();
        $ownerMock->method('first')->willReturn(['id' => 5, 'email' => 'jean@example.com']);

        $service = $this->buildService(ownerModel: $ownerMock);
        $result  = $service->createWithPendingOwner($this->validInput());

        $this->assertFalse($result->success);
        $this->assertSame('duplicate_owner_email', $result->errorCode);
    }

    public function testEmailFailureStillReturnsSuccessCreation(): void
    {
        // Simulate email delivery failure (service returns failed result, owner/kermesse/token already committed)
        $emailMock = $this->createMock(EmailService::class);
        $emailMock->method('sendOwnerValidationEmail')
                  ->willReturn(new EmailDeliveryResult(false, 'SMTP failure'));

        $service = $this->buildService(emailService: $emailMock);
        $result  = $service->createWithPendingOwner($this->validInput());

        // Owner + kermesse are created even when email fails
        $this->assertTrue($result->success);
        $this->assertNotNull($result->emailResult);
        $this->assertFalse($result->emailResult->sent);
    }

    public function testPublicSlugDoesNotExceedVarchar255(): void
    {
        $capturedData = null;

        $kermesseMock = $this->createMock(KermesseModel::class);
        $kermesseMock->method('skipValidation')->willReturnSelf();
        $kermesseMock->method('insert')->willReturnCallback(
            function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return 10;
            }
        );

        // Name at the maximum allowed length
        $longName = str_repeat('a', 255);
        $input    = array_merge($this->validInput(), ['kermesse_name' => $longName]);

        $service = $this->buildService(kermesseModel: $kermesseMock);
        $service->createWithPendingOwner($input);

        $this->assertNotNull($capturedData);
        $this->assertLessThanOrEqual(255, strlen($capturedData['public_slug']),
            'public_slug must not exceed VARCHAR(255)');
    }

    public function testEmailFailureRevokesTokenSoResendIsNotBlocked(): void
    {
        // When the initial validation email fails during creation, the token must be revoked
        // so that the user can immediately resend via /owner/login without hitting the cooldown
        // (which only counts non-revoked active tokens).
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('issueOwnerValidationToken')
                     ->willReturn(new IssuedToken('raw-fake-token', 99));
        $tokenService->expects($this->once())->method('revokeToken')->with(99);

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOwnerValidationEmail')
                     ->willReturn(new EmailDeliveryResult(false, 'SMTP failure'));

        $service = $this->buildService(tokenService: $tokenService, emailService: $emailService);
        $result  = $service->createWithPendingOwner($this->validInput());

        $this->assertTrue($result->success, 'Creation still succeeds even when email fails');
        $this->assertFalse($result->emailResult->sent);
    }

    public function testTokenServiceIsCalledWithCorrectOwnerAndKermesseIds(): void
    {
        $capturedArgs = [];

        // Re-build owner mock with addMethods so the where chain resolves to insert(1)
        $ownerMock = $this->buildMockOwnerModel();

        $tokenMock = $this->createMock(TokenService::class);
        $tokenMock->expects($this->once())
                  ->method('issueOwnerValidationToken')
                  ->willReturnCallback(
                      function (int $ownerId, int $kermesseId, string $email) use (&$capturedArgs) {
                          $capturedArgs = [$ownerId, $kermesseId, $email];
                          return new IssuedToken('raw', 1);
                      }
                  );

        $service = $this->buildService(ownerModel: $ownerMock, tokenService: $tokenMock);
        $service->createWithPendingOwner($this->validInput());

        $this->assertSame(1, $capturedArgs[0]);  // ownerId
        $this->assertSame(10, $capturedArgs[1]); // kermesseId
        $this->assertSame('jean@example.com', $capturedArgs[2]);
    }
}
