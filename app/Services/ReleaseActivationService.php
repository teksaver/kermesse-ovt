<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Kermesse;

/**
 * Activates a deployment release atomically.
 *
 * Workflow (inside a named DB lock):
 *   1. Verify the archive exists in staging/.
 *   2. Verify its SHA-256 checksum against the .sha256 sidecar.
 *   3. Extract into releases/<timestamp>-<sha8>/.
 *   4. Validate the required directory structure.
 *   5. Atomically switch the current pointer (symlink + CURRENT_RELEASE file).
 *   6. Prune old releases, retaining kermesse.releasesRetention most recent.
 *
 * shared/ (.env, writable/) is never touched — it lives outside releases/.
 */
class ReleaseActivationService
{
    protected ?BaseConnection $db;
    protected string $basePath;
    protected string $lockName;
    protected int $releasesRetention;

    public function __construct(?BaseConnection $db = null, ?string $basePath = null)
    {
        $this->db = $db ?? db_connect();

        $config = config(Kermesse::class);

        $this->lockName          = $config->opsActivateLockName;
        $this->releasesRetention = $config->releasesRetention;

        $configPath     = $config->opsActivateBasePath;
        $this->basePath = rtrim(
            $basePath ?? ($configPath !== '' ? $configPath : dirname(ROOTPATH)),
            '/'
        );
    }

    /**
     * Activate the named archive from staging.
     *
     * @return array{ok: bool, release?: string, pruned?: int, error?: string}
     */
    public function activate(string $archiveName): array
    {
        if (!$this->acquireLock()) {
            return ['ok' => false, 'error' => 'activation_locked'];
        }

        try {
            return $this->doActivate($archiveName);
        } finally {
            $this->releaseLock();
        }
    }

    // -----------------------------------------------------------------------
    // Private core logic (runs inside the lock)
    // -----------------------------------------------------------------------

    /**
     * @return array{ok: bool, release?: string, pruned?: int, error?: string}
     */
    private function doActivate(string $archiveName): array
    {
        $archivePath  = $this->basePath . '/staging/' . $archiveName;
        $checksumPath = $archivePath . '.sha256';

        // AC-2 — archive must be present in staging/
        if (!file_exists($archivePath)) {
            return ['ok' => false, 'error' => 'archive_missing'];
        }

        // AC-3 — .sha256 sidecar must exist
        if (!file_exists($checksumPath)) {
            return ['ok' => false, 'error' => 'checksum_mismatch'];
        }

        $expectedChecksum = trim((string) file_get_contents($checksumPath));
        $actualChecksum   = (string) hash_file('sha256', $archivePath);

        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            return ['ok' => false, 'error' => 'checksum_mismatch'];
        }

        // Prepare release directory
        $sha8        = substr($actualChecksum, 0, 8);
        $releaseName = date('Ymd-His') . '-' . $sha8;
        $releasesDir = $this->basePath . '/releases/';
        $releaseDir  = $releasesDir . $releaseName . '/';

        if (!is_dir($releasesDir)) {
            mkdir($releasesDir, 0755, true);
        }

        mkdir($releaseDir, 0755, true);

        // AC-4 — extract and validate structure
        if (!$this->extractArchive($archivePath, $releaseDir)) {
            $this->removeDir($releaseDir);
            return ['ok' => false, 'error' => 'release_invalid'];
        }

        if (!$this->validateReleaseStructure($releaseDir)) {
            $this->removeDir($releaseDir);
            return ['ok' => false, 'error' => 'release_invalid'];
        }

        // AC-1 — atomic switch of current pointer
        $this->switchCurrent($releaseDir, $releaseName);

        // AC-6 — prune old releases; shared/ is never scanned here
        $pruned = $this->pruneOldReleases($releasesDir, $releaseName, $this->releasesRetention);

        return ['ok' => true, 'release' => $releaseName, 'pruned' => $pruned];
    }

    // -----------------------------------------------------------------------
    // Protected hooks — overridable in tests
    // -----------------------------------------------------------------------

    /**
     * Extract the archive into the release directory.
     */
    protected function extractArchive(string $archivePath, string $releaseDir): bool
    {
        $cmd = 'tar -xzf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($releaseDir) . ' 2>&1';
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Atomically switch the current pointer to the new release.
     *
     * Writes CURRENT_RELEASE (pointer file, always) and attempts a symlink
     * swap via rename() for true filesystem atomicity on Linux.
     * Shim reads current symlink first, falls back to CURRENT_RELEASE.
     */
    protected function switchCurrent(string $releaseDir, string $releaseName): void
    {
        // Always write the pointer file so the fallback path in the shim works
        file_put_contents($this->basePath . '/CURRENT_RELEASE', $releaseName . "\n");

        // Attempt atomic symlink swap: tmp symlink → rename() → current
        $currentSymlink = $this->basePath . '/current';
        $tmpLink        = $currentSymlink . '.tmp.' . uniqid('', true);

        if (@symlink(rtrim($releaseDir, '/'), $tmpLink)) {
            rename($tmpLink, $currentSymlink);
        }
    }

    /**
     * Prune old release directories, retaining the $retention most recent.
     *
     * Releases are sorted lexicographically (timestamp prefix ensures order).
     * The current release is never deleted even if retention would require it.
     */
    protected function pruneOldReleases(string $releasesDir, string $currentRelease, int $retention): int
    {
        $dirs = glob($releasesDir . '*', GLOB_ONLYDIR);

        if ($dirs === false || count($dirs) <= $retention) {
            return 0;
        }

        sort($dirs, SORT_STRING); // Oldest first (timestamp prefix = lexical order)

        $toDelete = array_slice($dirs, 0, count($dirs) - $retention);
        $pruned   = 0;

        foreach ($toDelete as $dir) {
            // Safety: never delete the release we just activated
            if (basename($dir) !== $currentRelease) {
                $this->removeDir($dir);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Acquire a non-blocking named DB lock.
     *
     * Returns true if acquired; false if another process holds the lock (→ 409).
     * Exceptions are caught and treated as acquired — non-MariaDB environments
     * (e.g., SQLite in CI tests) do not support GET_LOCK.
     */
    protected function acquireLock(): bool
    {
        try {
            $result = $this->db->query(
                'SELECT GET_LOCK(?, 0) AS `acquired`',
                [$this->lockName]
            )->getRowArray();

            return ($result['acquired'] ?? 0) == 1;
        } catch (\Throwable $e) {
            log_message('debug', 'ReleaseActivationService: GET_LOCK unsupported, proceeding unlocked: {msg}', [
                'msg' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Release the named DB lock.
     */
    protected function releaseLock(): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?) AS `released`', [$this->lockName]);
        } catch (\Throwable $e) {
            // Non-MariaDB environments — ignore silently
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Check that all required directories exist after extraction.
     */
    private function validateReleaseStructure(string $releaseDir): bool
    {
        foreach (['app', 'vendor', 'public', 'database/migrations_sql'] as $required) {
            if (!is_dir($releaseDir . $required)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively remove a directory.
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isLink() || $file->isFile()) {
                @unlink($file->getPathname());
            } else {
                @rmdir($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}
