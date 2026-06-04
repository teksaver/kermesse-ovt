<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the kermesse creation form (GET /create).
 *
 * @internal
 */
final class CreateKermesseFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testCreateFormRespondsOK(): void
    {
        $result = $this->get('create');

        $result->assertOK();
    }

    public function testCreateFormShowsRequiredFields(): void
    {
        $result = $this->get('create');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('name="owner_name"', $body);
        $this->assertStringContainsString('name="owner_email"', $body);
        $this->assertStringContainsString('name="kermesse_name"', $body);
        $this->assertStringContainsString('name="event_date"', $body);
        $this->assertStringContainsString('name="location"', $body);
        $this->assertStringContainsString('name="short_description"', $body);
    }

    public function testCreateFormShowsVisibleLabels(): void
    {
        $result = $this->get('create');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('Votre nom', $body);
        $this->assertStringContainsString('Votre adresse email', $body);
        $this->assertStringContainsString('Nom de la kermesse', $body);
        $this->assertStringContainsString('Date de', $body);
        $this->assertStringContainsString('Lieu', $body);
        $this->assertStringContainsString('Description courte', $body);
    }

    public function testCreateFormContainsCsrfToken(): void
    {
        $result = $this->get('create');
        $body   = $result->response()->getBody();

        $this->assertStringContainsString('csrf_test_name', $body);
    }

    public function testCreateFormHasAccessibleFieldIds(): void
    {
        $result = $this->get('create');
        $body   = $result->response()->getBody();

        // Labels linked to fields via for/id
        $this->assertStringContainsString('for="field-owner_name"', $body);
        $this->assertStringContainsString('id="field-owner_name"', $body);
        $this->assertStringContainsString('for="field-owner_email"', $body);
        $this->assertStringContainsString('id="field-owner_email"', $body);
    }
}
