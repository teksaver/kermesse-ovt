<?php

declare(strict_types=1);

/**
 * Script d'activation autonome et ephemere pour les deploiements Ouvaton.
 *
 * Il ne charge ni CodeIgniter, ni vendor/autoload.php, ni la release courante :
 * il doit rester utilisable quand l'hebergeur n'a pas encore de release active
 * ou quand l'installation courante est cassee.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$expectedToken = '__BOOTSTRAP_TOKEN__';
$remoteFolder = '__REMOTE_FOLDER__';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    if (
        $expectedToken === ''
        || $expectedToken === '__' . 'BOOTSTRAP_TOKEN__'
        || !hash_equals($expectedToken, (string) ($_SERVER['HTTP_X_KERMESSE_BOOTSTRAP_TOKEN'] ?? ''))
    ) {
        respond(403, ['ok' => false, 'error' => 'ops_unauthorized']);
    }

    if (!is_safe_remote_folder($remoteFolder)) {
        respond(500, ['ok' => false, 'error' => 'invalid_remote_folder']);
    }

    $homeDir = dirname(__DIR__);
    $kermDir = realpath($homeDir . '/' . $remoteFolder);
    if ($kermDir === false || !is_dir($kermDir)) {
        respond(500, ['ok' => false, 'error' => 'kermesse_root_missing']);
    }

    $archivePath = $kermDir . '/staging/kermesse-deploy.tar.gz';
    $checksumPath = $archivePath . '.sha256';
    $releasesDir = $kermDir . '/releases';
    $lockDir = $kermDir . '/shared/writable/cache';

    if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
        respond(500, ['ok' => false, 'error' => 'lock_dir_unavailable']);
    }

    $lockHandle = fopen($lockDir . '/ops-bootstrap-activate.lock', 'c');
    if ($lockHandle === false) {
        respond(500, ['ok' => false, 'error' => 'lock_unavailable']);
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        respond(409, ['ok' => false, 'error' => 'activation_locked']);
    }

    $result = activate_release($kermDir, $archivePath, $checksumPath, $releasesDir);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    respond(200, $result);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'internal_error', 'message' => $e->getMessage()]);
}

/**
 * @return array{ok: bool, release: string, pruned: int, symlink: bool}
 */
function activate_release(string $kermDir, string $archivePath, string $checksumPath, string $releasesDir): array
{
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        respond(400, ['ok' => false, 'error' => 'archive_missing']);
    }

    if (!is_file($checksumPath) || !is_readable($checksumPath)) {
        respond(400, ['ok' => false, 'error' => 'checksum_mismatch']);
    }

    $expectedChecksum = read_expected_checksum($checksumPath);
    $actualChecksum = hash_file('sha256', $archivePath);
    if ($actualChecksum === false || !hash_equals($expectedChecksum, $actualChecksum)) {
        respond(400, ['ok' => false, 'error' => 'checksum_mismatch']);
    }

    if (!class_exists(PharData::class)) {
        respond(500, ['ok' => false, 'error' => 'phardata_unavailable']);
    }

    if (!validate_tar_gz_archive($archivePath)) {
        respond(400, ['ok' => false, 'error' => 'release_invalid']);
    }

    if (!is_dir($releasesDir) && !mkdir($releasesDir, 0755, true) && !is_dir($releasesDir)) {
        respond(500, ['ok' => false, 'error' => 'failed_to_create_releases_dir']);
    }

    $releaseName = date('Ymd-His') . '-' . substr($actualChecksum, 0, 8) . '-' . bin2hex(random_bytes(4));
    $releaseDir = $releasesDir . '/' . $releaseName;

    if (!mkdir($releaseDir, 0755, true)) {
        respond(500, ['ok' => false, 'error' => 'failed_to_create_release_dir']);
    }

    try {
        $archive = new PharData($archivePath);
        $archive->extractTo($releaseDir, null, true);
    } catch (Throwable $e) {
        remove_release_dir($releaseDir, $releasesDir);
        respond(500, ['ok' => false, 'error' => 'extraction_failed', 'message' => $e->getMessage()]);
    }

    if (!validate_release_structure($releaseDir)) {
        remove_release_dir($releaseDir, $releasesDir);
        respond(400, ['ok' => false, 'error' => 'release_invalid']);
    }

    $symlinkUpdated = switch_current($kermDir, $releaseDir, $releaseName);
    @unlink($archivePath);
    @unlink($checksumPath);

    $pruned = prune_old_releases($releasesDir, $releaseName, read_retention($kermDir));

    return ['ok' => true, 'release' => $releaseName, 'pruned' => $pruned, 'symlink' => $symlinkUpdated];
}

function read_expected_checksum(string $checksumPath): string
{
    $rawChecksum = trim((string) file_get_contents($checksumPath));
    $expectedChecksum = strtolower((string) preg_split('/\s+/', $rawChecksum, 2)[0]);

    if (strlen($expectedChecksum) !== 64 || !ctype_xdigit($expectedChecksum)) {
        respond(400, ['ok' => false, 'error' => 'checksum_mismatch']);
    }

    return $expectedChecksum;
}

function validate_release_structure(string $releaseDir): bool
{
    foreach (['app', 'vendor', 'public', 'database/migrations_sql'] as $required) {
        if (!is_dir($releaseDir . '/' . $required)) {
            return false;
        }
    }

    return true;
}

function switch_current(string $kermDir, string $releaseDir, string $releaseName): bool
{
    $pointerFile = $kermDir . '/CURRENT_RELEASE';
    $tmpPointer = $pointerFile . '.tmp.' . bin2hex(random_bytes(4));

    if (file_put_contents($tmpPointer, $releaseName . "\n", LOCK_EX) === false) {
        respond(500, ['ok' => false, 'error' => 'pointer_write_failed']);
    }

    if (!rename($tmpPointer, $pointerFile)) {
        @unlink($tmpPointer);
        respond(500, ['ok' => false, 'error' => 'pointer_write_failed']);
    }

    $currentSymlink = $kermDir . '/current';
    $tmpLink = $currentSymlink . '.tmp.' . bin2hex(random_bytes(4));

    if (!@symlink($releaseDir, $tmpLink)) {
        return false;
    }

    if (!@rename($tmpLink, $currentSymlink)) {
        @unlink($tmpLink);
        return false;
    }

    return true;
}

function read_retention(string $kermDir): int
{
    $retention = 3;
    $envFile = $kermDir . '/shared/.env';

    if (is_file($envFile) && is_readable($envFile)) {
        $envContent = (string) file_get_contents($envFile);
        if (preg_match('/^[[:space:]]*kermesse\.releasesRetention[[:space:]]*=[[:space:]]*(\d+)/m', $envContent, $matches)) {
            $retention = (int) $matches[1];
        }
    }

    return max(1, $retention);
}

function prune_old_releases(string $releasesDir, string $currentRelease, int $retention): int
{
    $dirs = glob(rtrim($releasesDir, '/') . '/*', GLOB_ONLYDIR);
    if ($dirs === false || count($dirs) <= $retention) {
        return 0;
    }

    sort($dirs, SORT_STRING);
    $toDelete = array_slice($dirs, 0, count($dirs) - $retention);
    $pruned = 0;

    foreach ($toDelete as $dir) {
        if (basename($dir) === $currentRelease) {
            continue;
        }

        remove_release_dir($dir, $releasesDir);
        $pruned++;
    }

    return $pruned;
}

function remove_release_dir(string $dir, string $releasesDir): void
{
    $realDir = realpath($dir);
    $realBase = realpath($releasesDir);
    if ($realDir === false || $realBase === false || !str_starts_with($realDir, $realBase . DIRECTORY_SEPARATOR)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
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

function validate_tar_gz_archive(string $archivePath): bool
{
    $handle = gzopen($archivePath, 'rb');
    if ($handle === false) {
        return false;
    }

    $zeroBlocks = 0;
    $pendingPath = null;

    try {
        while (!gzeof($handle)) {
            $header = read_gzip_bytes($handle, 512);
            if ($header === '') {
                break;
            }

            if (strlen($header) !== 512) {
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
            if (!has_valid_tar_checksum($header)) {
                return false;
            }

            $typeFlag = $header[156] === "\0" ? '0' : $header[156];
            $size = read_tar_octal(substr($header, 124, 12));
            if ($size === null) {
                return false;
            }

            $path = $pendingPath ?? read_tar_path($header);
            $pendingPath = null;

            if ($typeFlag === 'x') {
                $payload = read_tar_payload($handle, $size);
                if ($payload === null) {
                    return false;
                }

                $paxPath = read_pax_path($payload);
                if ($paxPath !== null) {
                    if (!is_safe_archive_path($paxPath)) {
                        return false;
                    }
                    $pendingPath = $paxPath;
                }
                continue;
            }

            if ($typeFlag === 'L') {
                $payload = read_tar_payload($handle, $size);
                if ($payload === null) {
                    return false;
                }

                $longPath = rtrim($payload, "\0");
                if (!is_safe_archive_path($longPath)) {
                    return false;
                }

                $pendingPath = $longPath;
                continue;
            }

            if ($typeFlag === 'K') {
                if (!skip_tar_payload($handle, $size)) {
                    return false;
                }
                continue;
            }

            if (in_array($typeFlag, ['1', '2'], true)) {
                return false;
            }

            if (!in_array($typeFlag, ['0', '5', '7'], true)) {
                return false;
            }

            if (!is_safe_archive_path($path)) {
                return false;
            }

            if (!skip_tar_payload($handle, $size)) {
                return false;
            }
        }
    } finally {
        gzclose($handle);
    }

    return true;
}

function read_gzip_bytes($handle, int $bytes): string
{
    $data = '';
    while (strlen($data) < $bytes && !gzeof($handle)) {
        $chunk = gzread($handle, $bytes - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }

    return $data;
}

function read_tar_payload($handle, int $size): ?string
{
    $payload = read_gzip_bytes($handle, $size);
    if (strlen($payload) !== $size) {
        return null;
    }

    $padding = (512 - ($size % 512)) % 512;
    if ($padding > 0 && strlen(read_gzip_bytes($handle, $padding)) !== $padding) {
        return null;
    }

    return $payload;
}

function skip_tar_payload($handle, int $size): bool
{
    $remaining = $size;
    while ($remaining > 0) {
        $chunkSize = min($remaining, 8192);
        if (strlen(read_gzip_bytes($handle, $chunkSize)) !== $chunkSize) {
            return false;
        }
        $remaining -= $chunkSize;
    }

    $padding = (512 - ($size % 512)) % 512;
    return $padding === 0 || strlen(read_gzip_bytes($handle, $padding)) === $padding;
}

function read_tar_octal(string $value): ?int
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

function has_valid_tar_checksum(string $header): bool
{
    $stored = read_tar_octal(substr($header, 148, 8));
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

function read_tar_path(string $header): string
{
    $name = rtrim(substr($header, 0, 100), "\0");
    $prefix = rtrim(substr($header, 345, 155), "\0");

    return $prefix === '' ? $name : $prefix . '/' . $name;
}

function read_pax_path(string $payload): ?string
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

function is_safe_archive_path(string $path): bool
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

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function is_safe_remote_folder(string $folder): bool
{
    return $folder !== ''
        && !str_starts_with($folder, '/')
        && !str_contains($folder, '..')
        && preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $folder) === 1;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}
