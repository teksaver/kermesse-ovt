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

    public function testErrorSummaryLinksPointToExistingFormFields(): void
    {
        // Error links in the summary must point to form fields that actually exist in the DOM.
        $result = $this->post('kermesses', ['csrf_test_name' => csrf_hash()]);

        $body = $result->response()->getBody();

        // Each error link target (href="#field-X") must have a matching id="field-X" in the form.
        if (str_contains($body, 'href="#field-owner_name"')) {
            $this->assertStringContainsString('id="field-owner_name"', $body,
                'Error link target #field-owner_name must exist in the form');
        }
        if (str_contains($body, 'href="#field-owner_email"')) {
            $this->assertStringContainsString('id="field-owner_email"', $body,
                'Error link target #field-owner_email must exist in the form');
        }
        if (str_contains($body, 'href="#field-kermesse_name"')) {
            $this->assertStringContainsString('id="field-kermesse_name"', $body,
                'Error link target #field-kermesse_name must exist in the form');
        }

        // No link should point to a non-rendered field (e.g., 'general' errors must not have href)
        $this->assertStringNotContainsString('href="#field-general"', $body,
            'General errors must not have a broken field anchor link');
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
