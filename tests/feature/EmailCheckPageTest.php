<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Test that the email_check view renders correctly.
 *
 * Since POST /kermesses requires a real DB to produce the email_check page,
 * this test directly renders the view to validate its output.
 *
 * @internal
 */
final class EmailCheckPageTest extends CIUnitTestCase
{
    public function testEmailCheckPageShowsConfirmationMessage(): void
    {
        $html = view('owner/email_check', [
            'kermesseName' => 'Kermesse de Noël',
            'ownerEmail'   => 'jean@example.com',
        ]);

        $this->assertStringContainsString('Vérifiez votre email', $html);
        $this->assertStringContainsString('configurer la kermesse', $html);
        $this->assertStringContainsString('Kermesse de Noël', $html);
        $this->assertStringContainsString('jean@example.com', $html);
    }

    public function testEmailCheckPageDoesNotLeakSensitiveInfo(): void
    {
        $html = view('owner/email_check', [
            'kermesseName' => 'Test',
            'ownerEmail'   => 'test@example.com',
        ]);

        $this->assertStringNotContainsString('token', strtolower($html));
        $this->assertStringNotContainsString('password', strtolower($html));
        $this->assertStringNotContainsString('.env', $html);
    }

    public function testEmailCheckPageWhenEmailSentShowsNormalMessage(): void
    {
        $html = view('owner/email_check', [
            'kermesseName' => 'Kermesse de Mai',
            'ownerEmail'   => 'alice@example.com',
            'emailSent'    => true,
        ]);

        $this->assertStringContainsString('alice@example.com', $html);
        $this->assertStringContainsString('lien de validation', $html);
        // Should NOT show the failure warning
        $this->assertStringNotContainsString('problème technique', $html);
        $this->assertStringNotContainsString('me connecter', $html);
    }

    public function testEmailCheckPageWhenEmailFailedShowsWarning(): void
    {
        $html = view('owner/email_check', [
            'kermesseName' => 'Kermesse de Mai',
            'ownerEmail'   => 'alice@example.com',
            'emailSent'    => false,
        ]);

        // Should show failure warning with guidance
        $this->assertStringContainsString('problème technique', $html);
        $this->assertStringContainsString('me connecter', $html);
        // Should still confirm kermesse was created
        $this->assertStringContainsString('Kermesse de Mai', $html);
        // Should NOT claim email was sent
        $this->assertStringNotContainsString('lien de validation', $html);
    }
}
