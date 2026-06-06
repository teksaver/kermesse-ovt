<?php

namespace App\Controllers\Ops;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Kermesse;

/**
 * Temporary ops endpoint reporting real runtime configuration facts.
 *
 * Protected by OpsAuthFilter (HMAC-SHA256) via route configuration, then gated
 * by kermesse.opsProbeEnabled. Used to measure the deployed target runtime so
 * the local environment can be calibrated against it (infra parity).
 *
 * Returns runtime facts only — never .env values, credentials, secrets,
 * server paths, phpinfo() output or request headers.
 */
class ProbeController extends BaseController
{
    /**
     * POST /ops/probe
     */
    public function probe(): ResponseInterface
    {
        $config = config(Kermesse::class);

        // Feature gate. Runs only after OpsAuthFilter has authenticated the call,
        // so an unauthenticated caller never learns whether the probe is enabled.
        if ($config->opsProbeEnabled !== true) {
            return $this->response
                ->setStatusCode(403)
                ->setJSON(['error' => 'probe_disabled']);
        }

        try {
            // Sorted for a stable output that can be diffed against the local probe.
            $extensions = get_loaded_extensions();
            sort($extensions, SORT_STRING);

            // ini_get() returns the values as PHP exposes them (sizes keep the
            // php.ini shorthand notation) so they compare directly with php.ini.
            $row            = db_connect()->query('SELECT VERSION() AS version')->getRowArray();
            $mariadbVersion = $row['version'] ?? '';

            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'php_version'         => PHP_VERSION,
                    'memory_limit'        => ini_get('memory_limit'),
                    'max_execution_time'  => ini_get('max_execution_time'),
                    'post_max_size'       => ini_get('post_max_size'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'extensions'          => $extensions,
                    'mariadb_version'     => $mariadbVersion,
                ]);
        } catch (\Throwable $e) {
            // Log server-side only; never leak SQL, stack traces, paths or secrets.
            log_message('critical', 'ProbeController: unhandled error: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'probe_failed']);
        }
    }
}
