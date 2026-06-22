<?php

namespace App\Controllers\Ops;

use App\Controllers\BaseController;
use App\Services\MigrationRunnerService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Ops endpoint for applying database migrations.
 *
 * Protected by OpsAuthFilter (HMAC-SHA256) via route configuration.
 */
class MigrationController extends BaseController
{
    /**
     * POST /ops/migrate/status
     *
     * Read-only migration state: classifies each discovered migration as pending/applied/failed.
     * Never acquires a lock, never applies anything, never modifies data.
     * Any DB exception bubbles up as a 500 (fail-fast rule).
     */
    public function status(): ResponseInterface
    {
        try {
            $runner = new MigrationRunnerService();
            $result = $runner->status();

            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'ok'      => true,
                    'pending' => $result['pending'],
                    'applied' => $result['applied'],
                    'failed'  => $result['failed'],
                ]);
        } catch (\Throwable $e) {
            log_message('critical', 'MigrationController::status: unhandled error: {message}', [
                'message' => $e,
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok'    => false,
                    'error' => 'status_error',
                ]);
        }
    }

    /**
     * POST /ops/migrate
     *
     * Runs pending SQL migrations and returns a minimal JSON summary.
     * Never exposes raw SQL, stack traces, secrets or environment variables.
     *
     * Optional JSON body parameter:
     *   until_version (string) — run only migrations up to and including this version key.
     *                            Used to implement phased expand/contract deployments:
     *                            Phase 1 (compat view) before activation, Phase 2 (rename) after.
     */
    public function migrate(): ResponseInterface
    {
        try {
            $body         = $this->request->getJSON(true) ?? [];
            $untilVersion = isset($body['until_version']) ? trim((string) $body['until_version']) : null;

            if ($untilVersion !== null) {
                if ($untilVersion === '') {
                    return $this->response
                        ->setStatusCode(400)
                        ->setJSON(['ok' => false, 'error' => 'until_version_empty']);
                }

                // Validate format: <14-digit timestamp>_<slug> — same rule as version in DriftController.
                if (!preg_match('/^\d{14}_[a-z0-9_]+$/', $untilVersion)) {
                    return $this->response
                        ->setStatusCode(400)
                        ->setJSON(['ok' => false, 'error' => 'until_version_invalid_format']);
                }
            }

            $runner = new MigrationRunnerService();
            $result = $untilVersion !== null
                ? $runner->runUpTo($untilVersion)
                : $runner->run();

            $statusCode = $result['ok'] ? 200 : 500;

            return $this->response
                ->setStatusCode($statusCode)
                ->setJSON([
                    'ok'      => $result['ok'],
                    'applied' => count($result['applied']),
                    'skipped' => count($result['skipped']),
                    'failed'  => count($result['failed']),
                ]);
        } catch (\Throwable $e) {
            log_message('critical', 'MigrationController: unhandled error: {message}', [
                'message' => $e,
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok'      => false,
                    'applied' => 0,
                    'skipped' => 0,
                    'failed'  => 1,
                ]);
        }
    }
}
