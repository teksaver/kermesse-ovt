<?php

use CodeIgniter\Test\CIUnitTestCase;
use App\Services\EmailService;
use App\Services\EmailDeliveryResult;
use App\Models\EmailEventModel;

/**
 * Unit tests for EmailService.
 *
 * Uses a mock EmailEventModel to verify email_events recording.
 *
 * @internal
 */
final class EmailServiceTest extends CIUnitTestCase
{
    public function testSendOwnerValidationEmailRecordsEvent(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new EmailService($mockModel);

        // The email will fail (no real SMTP), but the event must be recorded
        $result = $service->sendOwnerValidationEmail(
            'owner@example.com',
            'Jean',
            'Ma Kermesse',
            'https://example.com/owner/validate/fake-token',
        );

        $this->assertInstanceOf(EmailDeliveryResult::class, $result);
        $this->assertNotNull($capturedData);
        $this->assertSame('owner_validation', $capturedData['event_type']);
        $this->assertSame('owner@example.com', $capturedData['recipient_email']);
        $this->assertContains($capturedData['status'], ['sent', 'failed']);
    }

    public function testRecipientHashIsSha256OfNormalizedEmail(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new EmailService($mockModel);
        $service->sendOwnerValidationEmail(
            ' Owner@Example.COM ',
            'Test',
            'Test Kermesse',
            'https://example.com/validate/x',
        );

        $expectedHash = hash('sha256', 'owner@example.com');
        $this->assertSame($expectedHash, $capturedData['recipient_hash']);
    }

    public function testEmailFailureRecordsFailedStatus(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        // Pass a non-http URL: the email view throws InvalidArgumentException → caught → status=failed
        $service = new EmailService($mockModel);
        $result  = $service->sendOwnerValidationEmail(
            'fail@example.com',
            'Owner',
            'Kermesse',
            'not-a-valid-url',
        );

        $this->assertNotNull($capturedData);
        $this->assertSame('failed', $capturedData['status']);
        $this->assertFalse($result->sent);
    }

    // ------------------------------------------------------------------
    // sendOwnerLoginEmail
    // ------------------------------------------------------------------

    public function testSendOwnerLoginEmailRecordsOwnerLoginEvent(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new EmailService($mockModel);
        $result  = $service->sendOwnerLoginEmail(
            'owner@example.com',
            'Jean',
            'Ma Kermesse',
            'https://example.com/owner/login/fake-token',
        );

        $this->assertInstanceOf(EmailDeliveryResult::class, $result);
        $this->assertNotNull($capturedData);
        $this->assertSame('owner_login', $capturedData['event_type'],
            'event_type must be owner_login, not owner_validation');
        $this->assertSame('owner@example.com', $capturedData['recipient_email']);
        $this->assertContains($capturedData['status'], ['sent', 'failed']);
    }

    public function testOwnerLoginEmailFailureRecordsFailedStatus(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        // Pass a non-http URL: the email view throws InvalidArgumentException → caught → status=failed
        $service = new EmailService($mockModel);
        $result  = $service->sendOwnerLoginEmail(
            'fail@example.com',
            'Owner',
            'Kermesse',
            'not-a-valid-login-url',
        );

        $this->assertNotNull($capturedData);
        $this->assertSame('owner_login', $capturedData['event_type']);
        $this->assertSame('failed', $capturedData['status']);
        $this->assertFalse($result->sent);
    }

    public function testOwnerLoginEmailRawTokenAbsentFromEventErrorMessage(): void
    {
        $capturedData = null;
        $rawToken     = 'super-secret-login-token-12345';

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new EmailService($mockModel);
        $service->sendOwnerLoginEmail(
            'owner@example.com',
            'Jean',
            'Ma Kermesse',
            'https://example.com/owner/login/' . $rawToken,
        );

        // The raw token URL must not appear in the error_message field of email_events
        $errorMsg = $capturedData['error_message'] ?? '';
        $this->assertStringNotContainsString($rawToken, (string) $errorMsg,
            'Raw token must not appear in email_events.error_message');
    }

    // ------------------------------------------------------------------
    // sendSignupConfirmationEmail — Story 3.5
    // ------------------------------------------------------------------

    public function testSendSignupConfirmationEmailRecordsEvent(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        $service = new EmailService($mockModel);
        $result  = $service->sendSignupConfirmationEmail(
            'marie@exemple.fr',
            'Marie',
            'Kermesse de test',
            'Buvette',
            '2026-09-12 09:00:00',
            '2026-09-12 10:30:00',
        );

        $this->assertInstanceOf(EmailDeliveryResult::class, $result);
        $this->assertNotNull($capturedData);
        $this->assertSame('signup_confirmation', $capturedData['event_type']);
        $this->assertSame('marie@exemple.fr', $capturedData['recipient_email']);
        $this->assertSame(hash('sha256', 'marie@exemple.fr'), $capturedData['recipient_hash']);
        $this->assertContains($capturedData['status'], ['sent', 'failed']);

        // Diagnostic metadata must identify the signup context
        $metadata = json_decode((string) $capturedData['metadata'], true);
        $this->assertSame('Kermesse de test', $metadata['kermesse_name'] ?? null);
        $this->assertSame('Buvette', $metadata['stand_name'] ?? null);
    }

    public function testSignupConfirmationEmailFailureRecordsFailedStatus(): void
    {
        $capturedData = null;

        $mockModel = $this->createMock(EmailEventModel::class);
        $mockModel->method('skipValidation')->willReturnSelf();
        $mockModel->method('insert')->willReturnCallback(function (array $data) use (&$capturedData) {
            $capturedData = $data;
            return 1;
        });

        // Deterministic SMTP failure: the framework email service refuses to send
        $mockEmail = $this->createMock(\CodeIgniter\Email\Email::class);
        $mockEmail->method('send')->willReturn(false);
        \Config\Services::injectMock('email', $mockEmail);

        try {
            $service = new EmailService($mockModel);
            $result  = $service->sendSignupConfirmationEmail(
                'marie@exemple.fr',
                'Marie',
                'Kermesse de test',
                'Buvette',
                '2026-09-12 09:00:00',
                '2026-09-12 10:30:00',
            );
        } finally {
            \Config\Services::resetSingle('email');
        }

        $this->assertNotNull($capturedData);
        $this->assertSame('signup_confirmation', $capturedData['event_type']);
        $this->assertSame('failed', $capturedData['status']);
        $this->assertFalse($result->sent);
    }
}
