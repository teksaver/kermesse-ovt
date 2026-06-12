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
    protected DatabaseLockStrategy $lockStrategy;
    protected string $basePath;
    protected string $lockName;
    protected int $releasesRetention;

    public function __construct(?BaseConnection $db = null, ?string $basePath = null, ?DatabaseLockStrategy $lockStrategy = null)
    {
        $this->db = $db ?? db_connect();

        // Auto-detect lock strategy from driver when not explicitly provided.
        $this->lockStrategy = $lockStrategy ?? $this->buildLockStrategy($this->db);

        $config = config(Kermesse::class);

        $this->lockName          = $config->opsActivateLockName;
        $this->releasesRetention = $config->releasesRetention;

        $configPath     = trim($config->opsActivateBasePath);
        $this->basePath = rtrim(
            $basePath ?? ($configPath !== '' ? $configPath : $this->deriveBasePath(ROOTPATH)),
            '/'
        );
    }

    /**
     * Derive the deployment layout root when legacy environments do not yet
     * provide kermesse.opsActivateBasePath explicitly.
     */
    protected function deriveBasePath(string $rootPath): string
    {
        $root = rtrim($rootPath, '/');
        $parent = dirname($root);
        $candidates = [$root];

        if (basename($parent) === 'releases') {
            $candidates[] = dirname($parent);
        }

        if (basename($root) === 'current') {
            $candidates[] = $parent;
        }

        $candidates[] = $parent;

        foreach (array_unique($candidates) as $candidate) {
            if ($this->looksLikeDeployBase($candidate)) {
                return $candidate;
            }
        }

        return $parent;
    }

    private function looksLikeDeployBase(string $candidate): bool
    {
        return is_dir($candidate . '/staging')
            || is_dir($candidate . '/shared')
            || is_dir($candidate . '/releases');
    }

    /**
     * Build a lock strategy appropriate for the current database driver.
     */
    private function buildLockStrategy(BaseConnection $db): DatabaseLockStrategy
    {
        return ($db->DBDriver === 'MySQLi')
            ? new MariaDBLockStrategy($db)
            : new NullLockStrategy();
    }

    /**
     * Activate the named archive from staging.
     *
     * @return array{ok: bool, release?: string, pruned?: int, error?: string}
     */
    public function activate(string $archiveName): array
    {
        if (!$this->lockStrategy->acquire($this->lockName, 0)) {
            return ['ok' => false, 'error' => 'activation_locked'];
        }

        try {
            return $this->doActivate($archiveName);
        } finally {
            $this->lockStrategy->release($this->lockName);
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

        if (!is_readable($checksumPath) || !is_readable($archivePath)) {
            return ['ok' => false, 'error' => 'archive_missing'];
        }

        // The .sha256 file may contain either a raw 64-hex hash or sha256sum(1) format
        // ("<hash>  <filename>"). Extract the first whitespace-delimited token in both cases.
        $rawChecksum      = trim((string) file_get_contents($checksumPath));
        $expectedChecksum = strtolower(explode(' ', $rawChecksum, 2)[0]);

        // Strict format check: a valid SHA-256 digest is exactly 64 lowercase hex chars.
        // Rejecting non-hex or wrong-length values prevents silent mismatches caused by
        // truncated or malformed sidecar files.
        if (strlen($expectedChecksum) !== 64 || !ctype_xdigit($expectedChecksum)) {
            return ['ok' => false, 'error' => 'checksum_mismatch'];
        }

        $actualChecksum = (string) hash_file('sha256', $archivePath);

        if ($actualChecksum === '' || !hash_equals($expectedChecksum, $actualChecksum)) {
            return ['ok' => false, 'error' => 'checksum_mismatch'];
        }

        // Prepare release directory
        $sha8        = substr($actualChecksum, 0, 8);
        $releaseName = date('Ymd-His') . '-' . $sha8 . '-' . uniqid();
        $releasesDir = $this->basePath . '/releases/';
        $releaseDir  = $releasesDir . $releaseName . '/';

        if (!is_dir($releasesDir)) {
            mkdir($releasesDir, 0755, true);
        }

        if (!mkdir($releaseDir, 0755, true)) {
            return ['ok' => false, 'error' => 'internal_error'];
        }

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

        // Clean up the staging artifact
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
        if (file_exists($checksumPath)) {
            unlink($checksumPath);
        }

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

        if ($returnCode !== 0) {
            log_message('error', 'ReleaseActivationService: Extraction failed: ' . implode("\n", $output));
        }

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
        if (file_put_contents($this->basePath . '/CURRENT_RELEASE', $releaseName . "\n") === false) {
            log_message('error', 'ReleaseActivationService: Failed to write CURRENT_RELEASE pointer');
        }

        // Attempt atomic symlink swap: tmp symlink → rename() → current
        $currentSymlink = $this->basePath . '/current';
        $tmpLink        = $currentSymlink . '.tmp.' . uniqid('', true);

        if (symlink(rtrim($releaseDir, '/'), $tmpLink)) {
            if (!rename($tmpLink, $currentSymlink)) {
                unlink($tmpLink);
                throw new \RuntimeException('Failed to atomic rename symlink');
            }
        } else {
            throw new \RuntimeException('Failed to create atomic symlink');
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

        $realDir = realpath($dir);
        $realBase = realpath($this->basePath . '/releases/');
        if ($realDir === false || $realBase === false || !str_starts_with($realDir, $realBase)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isLink() || $file->isFile()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
