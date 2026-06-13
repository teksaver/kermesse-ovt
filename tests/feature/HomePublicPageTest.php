<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 1.2 — Page d'accueil publique (visiteur non connecté).
 */
class HomePublicPageTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testRootRouteReturnsOk(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
    }

    public function testRootPageContainsCreerKermesseAction(): void
    {
        $result = $this->get('/');

        $result->assertSee('Créer une kermesse');
    }

    public function testRootPageContainsMeConnecterAction(): void
    {
        $result = $this->get('/');

        $result->assertSee('Me connecter');
    }

    public function testRootPageLinksToAuthLogin(): void
    {
        $result = $this->get('/');

        $result->assertSee(site_url('auth/login'));
    }

    public function testRootPageLinksToCreate(): void
    {
        $result = $this->get('/');

        $result->assertSee(site_url('kermesse/create'));
    }
}
