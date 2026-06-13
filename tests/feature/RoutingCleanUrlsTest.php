<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Routing — URLs propres (sans `index.php`).
 *
 * Garde-fou contre la régression de `Config\App::$indexPage`. Tant que
 * `$indexPage` vaut `'index.php'`, tout `site_url()` injecte `index.php`
 * dans les liens générés (ex. `…/index.php/kermesse/1`), ce qui dégrade les
 * URLs et déclenche une boucle de redirection en prod sur le lien home
 * `site_url('/')` (cf. investigation index-php-routing). Le `.htaccess` sert
 * déjà les URLs propres, donc `$indexPage` ne joue qu'au niveau génération.
 *
 * @internal
 */
final class RoutingCleanUrlsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testSiteUrlForRouteHasNoIndexPage(): void
    {
        $this->assertStringNotContainsString(
            'index.php',
            site_url('kermesse/1'),
            'Les URLs générées ne doivent pas contenir index.php',
        );
    }

    public function testSiteUrlRootHasNoIndexPage(): void
    {
        $this->assertStringNotContainsString(
            'index.php',
            site_url('/'),
            'Le lien racine (nav home) ne doit pas contenir index.php',
        );
    }

    public function testPublicHomeRendersWithoutIndexPage(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
        $this->assertStringNotContainsString(
            'index.php',
            $result->response()->getBody(),
            'Le HTML rendu de la home publique ne doit contenir aucun index.php',
        );
    }
}
