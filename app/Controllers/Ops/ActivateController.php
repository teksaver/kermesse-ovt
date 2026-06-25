<?php

namespace App\Controllers\Ops;

use App\Controllers\BaseController;
use App\Services\ReleaseActivationService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Ops endpoint for atomically activating a deployment release.
 *
 * Protected by OpsAuthFilter (HMAC-SHA256) via route configuration.
 * Never exposes raw secrets, stack traces, SQL or filesystem paths.
 */
class ActivateController extends BaseController
{
    /**
     * POST /ops/activate
     *
     * Expected JSON body: {"archive": "kermesse-deploy.tar.gz"}
     *
     * Success:  200 {"ok":true,"release":"<name>","pruned":<N>}
     * Errors:   409 activation_locked
     *           422 archive_missing | checksum_mismatch | release_invalid
     *           500 internal_error
     */
    public function activate(): ResponseInterface
    {
        try {
            $body = $this->request->getJSON(true) ?? [];
            if (!is_array($body)) {
                $body = [];
            }
            
            if (!is_string($body['archive'] ?? '')) {
                $archiveName = '';
            } else {
                $archiveName = trim((string) ($body['archive'] ?? ''));
            }

            // Reject empty names and any path traversal attempt before hitting the service
            if ($archiveName === '' || basename($archiveName) !== $archiveName) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON(['error' => 'archive_missing']);
            }

            $service = new ReleaseActivationService();
            $result  = $service->activate($archiveName);

            if ($result['ok']) {
                return $this->response
                    ->setStatusCode(200)
                    ->setJSON([
                        'ok'      => true,
                        'release' => $result['release'],
                        'pruned'  => $result['pruned'],
                    ]);
            }

            $error      = $result['error'] ?? 'unknown';
            $statusCode = match ($error) {
                'activation_locked' => 409,
                'archive_missing',
                'checksum_mismatch',
                'release_invalid'   => 422,
                default             => 500,
            };

            $displayError = $statusCode === 500 ? 'internal_error' : $error;

            return $this->response
                ->setStatusCode($statusCode)
                ->setJSON(['error' => $displayError]);

        } catch (\Throwable $e) {
            log_message('critical', 'ActivateController: unhandled error: {message}', [
                'message' => $e->getMessage() . "\n" . $e->getTraceAsString(),
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'internal_error']);
        }
    }
}
