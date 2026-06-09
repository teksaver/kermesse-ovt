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
 * server paths, or phpinfo() output. HTTPS-related server variables set by
 * the proxy infrastructure are included so SSL parity can be verified.
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
        if (!$config->opsProbeEnabled) {
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

            // Capture only the server variables relevant to HTTPS detection.
            // These are set by the proxy infrastructure (not application secrets).
            // Null means the variable is absent; presence and value determine
            // which .htaccess rule to condition on for the SSL proxy fix.
            $sslContext = [
                'HTTPS'                   => $_SERVER['HTTPS'] ?? null,
                'HTTP_X_FORWARDED_PROTO'  => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
                'HTTP_X_FORWARDED_SSL'    => $_SERVER['HTTP_X_FORWARDED_SSL'] ?? null,
                'HTTP_X_FORWARDED_SCHEME' => $_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? null,
                'HTTP_FRONT_END_HTTPS'    => $_SERVER['HTTP_FRONT_END_HTTPS'] ?? null,
                'SERVER_PORT'             => $_SERVER['SERVER_PORT'] ?? null,
            ];

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
                    'ssl_context'         => $sslContext,
                ]);
        } catch (\Throwable $e) {
            // Log server-side only; never leak SQL, stack traces, paths or secrets.
            log_message('critical', 'ProbeController: unhandled error: {exception}', [
                'exception' => (string) $e,
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'probe_failed']);
        }
    }
}
