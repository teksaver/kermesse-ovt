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

        $configPath     = $config->opsActivateBasePath;
        $this->basePath = rtrim(
            $basePath ?? ($configPath !== '' ? $configPath : dirname(ROOTPATH)),
            '/'
        );
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
        if (!class_exists(\PharData::class)) {
            log_message('error', 'ReleaseActivationService: PharData is not available for archive extraction.');
            return false;
        }

        if (!$this->validateTarGzArchive($archivePath)) {
            return false;
        }

        try {
            $archive = new \PharData($archivePath);
            $archive->extractTo(rtrim($releaseDir, '/'), null, true);
        } catch (\Throwable $e) {
            log_message('error', 'ReleaseActivationService: Extraction failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Validate TAR metadata before PharData extraction.
     *
     * PharData is used for extraction, but raw TAR scanning lets us reject
     * dangerous entries that some readers may silently skip or normalize.
     */
    protected function validateTarGzArchive(string $archivePath): bool
    {
        $handle = gzopen($archivePath, 'rb');
        if ($handle === false) {
            log_message('error', 'ReleaseActivationService: Could not open archive for validation.');
            return false;
        }

        $zeroBlocks = 0;
        $pendingPath = null;

        try {
            while (!gzeof($handle)) {
                $header = $this->readGzipBytes($handle, 512);
                if ($header === '') {
                    break;
                }

                if (strlen($header) !== 512) {
                    log_message('error', 'ReleaseActivationService: Invalid tar header length.');
                    return false;
                }

                if ($header === str_repeat("\0", 512)) {
                    $zeroBlocks++;
                    if ($zeroBlocks >= 2) {
                        break;
                    }
                    continue;
                }

                $zeroBlocks = 0;

                if (!$this->hasValidTarChecksum($header)) {
                    log_message('error', 'ReleaseActivationService: Invalid tar header checksum.');
                    return false;
                }

                $typeFlag = $header[156] === "\0" ? '0' : $header[156];
                $size = $this->readTarOctal(substr($header, 124, 12));
                if ($size === null) {
                    log_message('error', 'ReleaseActivationService: Invalid tar entry size.');
                    return false;
                }

                $path = $pendingPath ?? $this->readTarPath($header);
                $pendingPath = null;

                if ($typeFlag === 'x') {
                    $payload = $this->readTarPayload($handle, $size);
                    if ($payload === null) {
                        return false;
                    }

                    $paxPath = $this->readPaxPath($payload);
                    if ($paxPath !== null) {
                        if (!$this->isSafeArchivePath($paxPath)) {
                            log_message('error', 'ReleaseActivationService: Unsafe pax archive path rejected: ' . $paxPath);
                            return false;
                        }
                        $pendingPath = $paxPath;
                    }

                    continue;
                }

                if ($typeFlag === 'L') {
                    $payload = $this->readTarPayload($handle, $size);
                    if ($payload === null) {
                        return false;
                    }

                    $longPath = rtrim($payload, "\0");
                    if (!$this->isSafeArchivePath($longPath)) {
                        log_message('error', 'ReleaseActivationService: Unsafe long archive path rejected: ' . $longPath);
                        return false;
                    }

                    $pendingPath = $longPath;
                    continue;
                }

                if ($typeFlag === 'K') {
                    if (!$this->skipTarPayload($handle, $size)) {
                        return false;
                    }

                    continue;
                }

                if (in_array($typeFlag, ['1', '2'], true)) {
                    log_message('error', 'ReleaseActivationService: Archive links are not allowed: ' . $path);
                    return false;
                }

                if (!in_array($typeFlag, ['0', '5', '7'], true)) {
                    log_message('error', 'ReleaseActivationService: Unsupported tar entry type rejected: ' . $typeFlag);
                    return false;
                }

                if (!$this->isSafeArchivePath($path)) {
                    log_message('error', 'ReleaseActivationService: Unsafe archive path rejected: ' . $path);
                    return false;
                }

                if (!$this->skipTarPayload($handle, $size)) {
                    return false;
                }
            }
        } finally {
            gzclose($handle);
        }

        return true;
    }

    protected function readTarPath(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");

        return $prefix === '' ? $name : $prefix . '/' . $name;
    }

    protected function isSafeArchivePath(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^\./+#', '', $path) ?? $path;
        $path = rtrim($path, '/');

        if ($path === '' || $path === '.') {
            return true;
        }

        if (str_starts_with($path, '/') || str_contains($path, '://')) {
            return false;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function readGzipBytes($handle, int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes && !gzeof($handle)) {
            $chunk = gzread($handle, $bytes - strlen($data));
            if ($chunk === false) {
                return $data;
            }
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function readTarPayload($handle, int $size): ?string
    {
        $payload = $this->readGzipBytes($handle, $size);
        if (strlen($payload) !== $size) {
            log_message('error', 'ReleaseActivationService: Invalid tar payload length.');
            return null;
        }

        $padding = (512 - ($size % 512)) % 512;
        if ($padding > 0 && strlen($this->readGzipBytes($handle, $padding)) !== $padding) {
            log_message('error', 'ReleaseActivationService: Invalid tar payload padding.');
            return null;
        }

        return $payload;
    }

    private function skipTarPayload($handle, int $size): bool
    {
        $remaining = $size;

        while ($remaining > 0) {
            $chunkSize = min($remaining, 8192);
            $chunk = $this->readGzipBytes($handle, $chunkSize);
            if (strlen($chunk) !== $chunkSize) {
                log_message('error', 'ReleaseActivationService: Invalid tar payload length.');
                return false;
            }
            $remaining -= $chunkSize;
        }

        $padding = (512 - ($size % 512)) % 512;
        if ($padding > 0 && strlen($this->readGzipBytes($handle, $padding)) !== $padding) {
            log_message('error', 'ReleaseActivationService: Invalid tar payload padding.');
            return false;
        }

        return true;
    }

    private function readTarOctal(string $value): ?int
    {
        $value = trim($value, " \0");
        if ($value === '') {
            return 0;
        }

        if (!preg_match('/^[0-7]+$/', $value)) {
            return null;
        }

        return octdec($value);
    }

    private function hasValidTarChecksum(string $header): bool
    {
        $stored = $this->readTarOctal(substr($header, 148, 8));
        if ($stored === null) {
            return false;
        }

        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actual = 0;
        for ($i = 0; $i < 512; $i++) {
            $actual += ord($checksumHeader[$i]);
        }

        return $stored === $actual;
    }

    private function readPaxPath(string $payload): ?string
    {
        foreach (explode("\n", $payload) as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d+ path=(.*)$/', $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
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
