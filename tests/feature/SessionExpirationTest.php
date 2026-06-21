<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Story 6.8 — Gérer l'expiration de session avec redirection gracieuse.
 *
 * AC1 : GET sur route protégée sans session → redirect vers /auth/login?redirect=<url>
 * AC2 : POST sans token CSRF + pas de session → SecurityException interceptée,
 *        redirect vers /auth/login avec message flash (pas de page 403 brute).
 * AC3 : POST sans token CSRF + session active → 403 standard (pas de redirect silencieux).
 * AC4 : Paramètre redirect avec URL externe → rejetée, fallback sur l'accueil connecté.
 *
 * @internal
 */
final class SessionExpirationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    // -----------------------------------------------------------------------
    // Task 1 — AuthFilter redirection GET (AC1)
    // -----------------------------------------------------------------------

    /**
     * AC1 : un GET sur une route protégée sans session doit rediriger
     * vers /auth/login en passant l'URL courante dans le param ?redirect=.
     */
    public function testGetExpiredSessionRedirectsToLoginWithRedirectParam(): void
    {
        $result = $this->withSession([])->get('dashboard');

        $result->assertRedirect();
        $location = $result->response()->getHeaderLine('Location');

        $this->assertStringContainsString('auth/login', $location,
            'La redirection doit pointer vers /auth/login');
        $this->assertStringContainsString('redirect=', $location,
            'Le paramètre redirect doit être présent dans la redirection');
        $this->assertStringContainsString('dashboard', urldecode($location),
            'L\'URL d\'origine (dashboard) doit être encodée dans redirect');
    }

    /**
     * AC1 (suite) : le MagicLinkController stocke redirect_url en session
     * lorsque le paramètre ?redirect= est présent sur GET /auth/login.
     */
    public function testShowLoginFormStoresValidRedirectInSession(): void
    {
        $result = $this->withSession([])->get('auth/login?redirect=/dashboard');

        $result->assertSessionHas('redirect_url');

        // CI4 TestResponse::getSessionVal() does not exist; read directly from $_SESSION.
        $stored = (string) ($_SESSION['redirect_url'] ?? '');
        $this->assertStringContainsString('dashboard', $stored,
            'L\'URL redirigée doit contenir "dashboard"');
    }

    // -----------------------------------------------------------------------
    // Task 2 — ExceptionHandler pour SecurityException POST (AC2, AC3)
    // -----------------------------------------------------------------------

    /**
     * AC2 : POST sans token CSRF avec session expirée → redirect vers /auth/login
     * avec message flash "Votre session a expiré" au lieu d'une page 403 brute.
     */
    public function testPostExpiredSessionSecurityExceptionRedirectsToLogin(): void
    {
        // Pas de session (session expirée), pas de token CSRF
        $result = $this->withSession([])->post('auth/login', ['email' => 'test@example.com']);

        $result->assertRedirect();
        $location = $result->response()->getHeaderLine('Location');

        $this->assertStringContainsString('auth/login', $location,
            'La SecurityException sans session doit rediriger vers /auth/login');
    }

    /**
     * AC3 : POST sans token CSRF mais avec session active (CSRF genuinement invalide)
     * → réponse 403 habituelle, pas de redirection silencieuse.
     */
    public function testGenuineCsrfFailureWithActiveSessionReturns403(): void
    {
        // Session active avec user_id, mais pas de token CSRF dans le POST
        $result = $this->withSession(['user_id' => 999, 'is_logged_in' => true])
                       ->post('auth/login', ['email' => 'test@example.com']);

        $this->assertSame(403, $result->response()->getStatusCode(),
            'Une SecurityException avec session active doit retourner 403, pas une redirection');
    }

    // -----------------------------------------------------------------------
    // Task 3 — Protection open redirect (AC4)
    // -----------------------------------------------------------------------

    /**
     * AC4 : un paramètre redirect pointant vers un domaine externe
     * est rejeté — la session ne stocke PAS l'URL malveillante.
     */
    public function testExternalRedirectParamIsRejected(): void
    {
        $result = $this->withSession([])->get('auth/login?redirect=http://evil.com/steal');

        // CI4 TestResponse::getSessionVal() does not exist; read directly from $_SESSION.
        $stored = (string) ($_SESSION['redirect_url'] ?? '');

        $this->assertNotSame('http://evil.com/steal', (string) $stored,
            'Une URL externe ne doit jamais être stockée telle quelle en session');
        $this->assertStringNotContainsString('evil.com', (string) $stored,
            'L\'URL de phishing ne doit pas apparaître dans la session redirect_url');
    }

    /**
     * AC4 (complément) : un paramètre redirect avec chemin relatif valide
     * est accepté et transformé en URL absolue de même origine.
     */
    public function testSameOriginRedirectParamIsAccepted(): void
    {
        $result = $this->withSession([])->get('auth/login?redirect=/profile');

        $result->assertSessionHas('redirect_url');
        // CI4 TestResponse::getSessionVal() does not exist; read directly from $_SESSION.
        $stored = (string) ($_SESSION['redirect_url'] ?? '');

        $this->assertNotEmpty($stored,
            'Un redirect relatif valide doit être stocké en session');
        $this->assertStringContainsString('profile', $stored,
            'L\'URL stockée doit contenir le chemin demandé');
        $this->assertStringNotContainsString('evil', $stored);
    }
}
