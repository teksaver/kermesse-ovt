<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * Drop-in replacement for CI4's built-in CSRF filter and the single owner of
 * CSRF-failure handling (Story 6.8). It converts a SecurityException into an
 * appropriate response instead of letting it propagate as a raw 403:
 *  - Active session (genuine CSRF mismatch): standard CI4 403 error page.
 *  - Expired session, AJAX/API client: 401 JSON (a browser redirect is useless to it).
 *  - Expired session, browser request: redirect to /auth/login, resuming on a safe
 *    same-origin GET page after re-authentication.
 *
 * Returning a ResponseInterface (rather than re-throwing) is deliberate: a thrown
 * SecurityException is not Responsable, so CodeIgniter::run() re-throws it and the
 * feature tests never see a 403 response.
 */
class CsrfFilter implements FilterInterface
{
    use BuildsLoginRedirect;

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $security = service('security');

        try {
            $security->verify($request);
        } catch (SecurityException) {
            // Active session → genuine CSRF failure: render CI4's standard 403 page
            // (not a blank body, not a silent login redirect).
            if (session()->get('user_id')) {
                return service('response')
                    ->setStatusCode(403)
                    ->setBody(view('errors/html/production'));
            }

            // Expired session: the CSRF token vanished with the session itself.
            // AJAX/API clients cannot follow an HTML redirect — give them a status
            // code they can act on instead.
            if ($request->isAJAX()) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['error' => 'session_expired']);
            }

            session()->setFlashdata('error', 'Votre session a expiré — reconnectez-vous pour continuer.');

            // Never replay the failed POST endpoint after login: it only accepts POST
            // and a GET redirect onto it would 405. Resume on the same-origin GET page
            // the user came from, when available.
            return redirect()->to($this->loginRedirectUrl($this->resumeTarget($request)));
        }

        return null;
    }

    /**
     * Derive a safe same-origin resume path from the Referer header, or null when
     * none is usable. Rejects cross-origin referers (open-redirect protection) and
     * auth routes (which would bounce the user back into the login flow).
     */
    private function resumeTarget(IncomingRequest $request): ?string
    {
        $referer = $request->getHeaderLine('Referer');
        if ($referer === '') {
            return null;
        }

        $refParts  = parse_url($referer);
        $baseParts = parse_url(site_url('/'));
        if (! is_array($refParts) || ! is_array($baseParts)) {
            return null;
        }

        if (
            ($refParts['scheme'] ?? null) !== ($baseParts['scheme'] ?? null)
            || ($refParts['host'] ?? null) !== ($baseParts['host'] ?? null)
            || ($refParts['port'] ?? null) !== ($baseParts['port'] ?? null)
        ) {
            return null;
        }

        $path = $refParts['path'] ?? '/';
        if (isset($refParts['query'])) {
            $path .= '?' . $refParts['query'];
        }

        if (str_starts_with(ltrim($path, '/'), 'auth/')) {
            return null;
        }

        return $path;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
