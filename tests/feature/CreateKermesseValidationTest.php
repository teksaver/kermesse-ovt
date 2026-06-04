<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /kermesses validation.
 *
 * @internal
 */
final class CreateKermesseValidationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testEmptyPostReDisplaysFormWithErrors(): void
    {
        $result = $this->post('kermesses', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $result->assertOK();

        $body = $result->response()->getBody();

        // Error summary should appear
        $this->assertStringContainsString('Veuillez corriger les erreurs suivantes', $body);
        // Specific error messages
        $this->assertStringContainsString('Veuillez saisir votre nom', $body);
        $this->assertStringContainsString('Veuillez saisir votre adresse email', $body);
    }

    public function testInvalidEmailShowsEmailError(): void
    {
        $result = $this->post('kermesses', [
            'csrf_test_name'    => csrf_hash(),
            'owner_name'        => 'Jean Dupont',
            'owner_email'       => 'not-an-email',
            'kermesse_name'     => 'Kermesse Test',
            'event_date'        => '2026-09-15',
            'location'          => 'Paris',
            'short_description' => 'Test description',
        ]);

        $result->assertOK();

        $body = $result->response()->getBody();
        $this->assertStringContainsString('Veuillez saisir une adresse email valide', $body);
    }

    public function testWhitespaceOnlyRequiredFieldsShowErrors(): void
    {
        $result = $this->post('kermesses', [
            'csrf_test_name'    => csrf_hash(),
            'owner_name'        => '   ',
            'owner_email'       => 'owner@example.com',
            'kermesse_name'     => '   ',
            'event_date'        => '2026-09-15',
            'location'          => '   ',
            'short_description' => '   ',
        ]);

        $result->assertOK();

        $body = $result->response()->getBody();

        $this->assertStringContainsString('Veuillez saisir votre nom', $body);
        $this->assertStringContainsString('Veuillez donner un nom', $body);
        $this->assertStringContainsString('Veuillez indiquer le lieu', $body);
    }

    public function testInvalidPostPreservesOldInput(): void
    {
        $result = $this->post('kermesses', [
            'csrf_test_name'    => csrf_hash(),
            'owner_name'        => 'Jean Dupont',
            'owner_email'       => '',
            'kermesse_name'     => 'Ma Super Kermesse',
            'event_date'        => '2026-09-15',
            'location'          => 'Paris',
            'short_description' => 'Description test',
        ]);

        $result->assertOK();

        $body = $result->response()->getBody();
        // Old values preserved
        $this->assertStringContainsString('Jean&#x20;Dupont', $body);
        $this->assertStringContainsString('Ma&#x20;Super&#x20;Kermesse', $body);
        $this->assertStringContainsString('Paris', $body);
    }

    public function testErrorPagesDoNotContainSensitiveData(): void
    {
        $result = $this->post('kermesses', [
            'csrf_test_name' => csrf_hash(),
        ]);

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('CREATE TABLE', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.env', $body);
    }
}
