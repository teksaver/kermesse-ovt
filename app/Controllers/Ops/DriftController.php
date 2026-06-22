<?php

namespace App\Controllers\Ops;

use App\Controllers\BaseController;
use App\Services\MigrationRunnerService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Kermesse;

/**
 * Ops endpoint for reconciling known migration checksum drifts.
 *
 * Protected by OpsAuthFilter (HMAC-SHA256) via route configuration.
 * Only versions whose current file exists on disk can be reconciled.
 */
class DriftController extends BaseController
{
    /**
     * POST /ops/fix-drift
     *
     * Reconciles a known checksum drift by verifying the actual schema effect
     * of the migration, then updating schema_versions to the current file checksum.
     *
     * Request body: {"version": "20260614121500_add_last_login_at_to_users"}
     */
    public function fixDrift(): ResponseInterface
    {
        try {
            $body    = $this->request->getJSON(true) ?? [];
            $version = trim((string) ($body['version'] ?? ''));

            if ($version === '') {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['ok' => false, 'error' => 'version_required']);
            }

            // Prevent path traversal: version must be exactly <14-digit timestamp>_<slug>.
            // This ensures no ../  or absolute paths can escape the migrations directory.
            if (!preg_match('/^\d{14}_[a-z0-9_]+$/', $version)) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['ok' => false, 'error' => 'version_invalid_format']);
            }

            $config = config(Kermesse::class);
            $migrationsPath = $config->opsMigrationPath !== ''
                ? $config->opsMigrationPath
                : ROOTPATH . 'database/migrations_sql';

            $filePath = rtrim($migrationsPath, '/') . '/' . $version . '.sql';

            if (!is_file($filePath)) {
                return $this->response
                    ->setStatusCode(404)
                    ->setJSON(['ok' => false, 'error' => 'migration_file_not_found', 'version' => $version]);
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                return $this->response
                    ->setStatusCode(500)
                    ->setJSON(['ok' => false, 'error' => 'migration_file_unreadable']);
            }

            $currentChecksum = hash('sha256', $content);

            $runner = new MigrationRunnerService();
            $result = $runner->reconcileChecksum($version, $currentChecksum);

            $statusCode = $result['ok'] ? 200 : 422;

            return $this->response
                ->setStatusCode($statusCode)
                ->setJSON(array_merge($result, ['version' => $version]));
        } catch (\Throwable $e) {
            log_message('critical', 'DriftController::fixDrift: unhandled error: {message}', [
                'message' => $e,
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['ok' => false, 'error' => 'internal_error']);
        }
    }
}
