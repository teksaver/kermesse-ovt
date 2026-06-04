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
}
