<?php

use App\Services\ReleaseActivationService;
use App\Services\DatabaseLockStrategy;
use App\Services\NullLockStrategy;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../_support/TmpDirTrait.php';

/**
 * Unit tests for ReleaseActivationService.
 *
 * Uses a temporary filesystem (sys_get_temp_dir) so no disk side-effect survives
 * the test run. DB lock methods are bypassed via test subclasses — MariaDB is
 * not required for these tests (lock DB scenario: see @group mariadb variant).
 *
 * @internal
 */
final class ReleaseActivationServiceTest extends CIUnitTestCase
{
    use TmpDirTrait;
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir() . '/kermesse_activate_' . uniqid('', true);

        mkdir($this->tmpBase . '/staging',  0755, true);
        mkdir($this->tmpBase . '/releases', 0755, true);

        config('Kermesse')->opsActivateBasePath  = $this->tmpBase;
        config('Kermesse')->opsActivateLockName  = 'kermesse_ops_activate_lock_test';
        config('Kermesse')->releasesRetention    = 3;
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmpBase);
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // AC-5 — Lock busy → 409 activation_locked
    // -----------------------------------------------------------------------

    public function testActivateReturnActivationLockedWhenLockBusy(): void
    {
        // Inject a lock strategy that always fails to acquire.
        $failingLock = new class implements DatabaseLockStrategy {
            public function acquire(string $name, int $timeout): bool { return false; }
            public function release(string $name): void {}
        };

        $service = new ReleaseActivationService(null, $this->tmpBase, $failingLock);

        $result = $service->activate('kermesse-deploy.tar.gz');

        $this->assertFalse($result['ok']);
        $this->assertSame('activation_locked', $result['error']);
    }

    // -----------------------------------------------------------------------
    // AC-2 — Archive absent → archive_missing
    // -----------------------------------------------------------------------

    public function testActivateReturnsArchiveMissingWhenNoArchiveInStaging(): void
    {
        $service = $this->makeService();

        $result = $service->activate('kermesse-deploy.tar.gz');

        $this->assertFalse($result['ok']);
        $this->assertSame('archive_missing', $result['error']);
    }

    // -----------------------------------------------------------------------
    // AC-3 — Sidecar .sha256 absent → checksum_mismatch
    // -----------------------------------------------------------------------

    public function testActivateReturnsChecksumMismatchWhenSidecarMissing(): void
    {
        // Archive exists but no .sha256 sidecar
        file_put_contents($this->tmpBase . '/staging/kermesse-deploy.tar.gz', 'dummy');

        $service = $this->makeService();
        $result  = $service->activate('kermesse-deploy.tar.gz');

        $this->assertFalse($result['ok']);
        $this->assertSame('checksum_mismatch', $result['error']);
    }

    // -----------------------------------------------------------------------
    // AC-3 — Checksum ne correspond pas → checksum_mismatch, current inchangé
    // -----------------------------------------------------------------------

    public function testActivateReturnsChecksumMismatchWhenChecksumDiffers(): void
    {
        $archivePath = $this->tmpBase . '/staging/kermesse-deploy.tar.gz';
        file_put_contents($archivePath, 'real content');
        file_put_contents($archivePath . '.sha256', 'wrong_checksum_value_not_a_sha256');

        $service = $this->makeService();
        $result  = $service->activate('kermesse-deploy.tar.gz');

        $this->assertFalse($result['ok']);
        $this->assertSame('checksum_mismatch', $result['error']);

        // current must not have been touched
        $this->assertFileDoesNotExist($this->tmpBase . '/current');
        $this->assertFileDoesNotExist($this->tmpBase . '/CURRENT_RELEASE');
    }

    // -----------------------------------------------------------------------
    // AC-4 — Structure de release invalide → release_invalid, extrait supprimé
    // -----------------------------------------------------------------------

    public function testActivateReturnsReleaseInvalidWhenRequiredDirMissing(): void
    {
        // Archive sans le dossier vendor/
        $archiveName = $this->createArchiveWithDirs(['app', 'public', 'database/migrations_sql']);
        $service     = $this->makeService();

        $result = $service->activate($archiveName);

        $this->assertFalse($result['ok']);
        $this->assertSame('release_invalid', $result['error']);

        // Le dossier extrait doit avoir été supprimé
        $releases = glob($this->tmpBase . '/releases/*', GLOB_ONLYDIR);
        $this->assertEmpty($releases, 'Release directory must be cleaned up on release_invalid');
    }

    public function testActivateReturnsReleaseInvalidWhenMigrationsDirMissing(): void
    {
        $archiveName = $this->createArchiveWithDirs(['app', 'vendor', 'public']);
        $service     = $this->makeService();

        $result = $service->activate($archiveName);

        $this->assertFalse($result['ok']);
        $this->assertSame('release_invalid', $result['error']);
    }

    // -----------------------------------------------------------------------
    // AC-1 — Activation réussie → 200, release nommée, current basculé
    // -----------------------------------------------------------------------

    public function testActivateSucceedsAndSwitchesCurrent(): void
    {
        $archiveName = $this->createValidArchive();
        $service     = $this->makeService();

        $result = $service->activate($archiveName);

        $this->assertTrue($result['ok'], 'Expected ok:true, got error: ' . ($result['error'] ?? 'none'));
        $this->assertNotEmpty($result['release']);
        $this->assertIsInt($result['pruned']);

        // CURRENT_RELEASE pointer must be written
        $this->assertFileExists($this->tmpBase . '/CURRENT_RELEASE');
        $pointer = trim((string) file_get_contents($this->tmpBase . '/CURRENT_RELEASE'));
        $this->assertSame($result['release'], $pointer);

        // Release directory must exist
        $this->assertDirectoryExists($this->tmpBase . '/releases/' . $result['release']);
    }

    // -----------------------------------------------------------------------
    // AC-6 — Rétention : seules les N dernières releases conservées
    // -----------------------------------------------------------------------

    public function testActivationPrunesOldReleasesAboveRetention(): void
    {
        config('Kermesse')->releasesRetention = 2;

        // Pre-populate 3 old releases (older than the one we'll create)
        foreach (['20200101-000001', '20200101-000002', '20200101-000003'] as $name) {
            mkdir($this->tmpBase . '/releases/' . $name, 0755, true);
        }

        $archiveName = $this->createValidArchive();
        $service     = $this->makeService();
        $result      = $service->activate($archiveName);

        $this->assertTrue($result['ok']);

        // Total releases after: 4 pre-existing + 1 new = 5, retention = 2, pruned = 3
        $this->assertGreaterThanOrEqual(1, $result['pruned']);

        $remaining = glob($this->tmpBase . '/releases/*', GLOB_ONLYDIR);
        $this->assertLessThanOrEqual(2, count($remaining));
    }

    public function testCurrentReleaseIsNeverPruned(): void
    {
        config('Kermesse')->releasesRetention = 1;

        $archiveName = $this->createValidArchive();
        $service     = $this->makeService();
        $result      = $service->activate($archiveName);

        $this->assertTrue($result['ok']);

        // The newly activated release must still exist regardless of retention
        $this->assertDirectoryExists($this->tmpBase . '/releases/' . $result['release']);
    }

    // -----------------------------------------------------------------------
    // AC-6 — .env et writable/ (dans shared/) ne sont jamais touchés
    // -----------------------------------------------------------------------

    public function testActivationDoesNotTouchSharedDirectory(): void
    {
        mkdir($this->tmpBase . '/shared/writable', 0755, true);
        file_put_contents($this->tmpBase . '/shared/.env', 'SECRET=production');

        $archiveName = $this->createValidArchive();
        $service     = $this->makeService();
        $result      = $service->activate($archiveName);

        $this->assertTrue($result['ok']);
        $this->assertFileExists($this->tmpBase . '/shared/.env');
        $this->assertSame('SECRET=production', file_get_contents($this->tmpBase . '/shared/.env'));
    }

    public function testDeriveBasePathAcceptsLegacyRootLayout(): void
    {
        $root = $this->tmpBase . '/kermesse';
        mkdir($root . '/staging', 0755, true);
        mkdir($root . '/shared', 0755, true);
        mkdir($root . '/releases', 0755, true);

        $this->assertSame($root, $this->deriveBasePathForTest($root));
    }

    public function testDeriveBasePathClimbsOutOfReleaseDirectory(): void
    {
        $base = $this->tmpBase . '/kermesse';
        $releaseRoot = $base . '/releases/20260612-092200-deadbeef';
        mkdir($base . '/staging', 0755, true);
        mkdir($base . '/shared', 0755, true);
        mkdir($releaseRoot, 0755, true);

        $this->assertSame($base, $this->deriveBasePathForTest($releaseRoot));
    }

    public function testDeriveBasePathUsesCurrentParentWhenSymlinkIsNotResolved(): void
    {
        $base = $this->tmpBase . '/kermesse';
        mkdir($base . '/staging', 0755, true);
        mkdir($base . '/shared', 0755, true);
        mkdir($base . '/releases', 0755, true);

        $this->assertSame($base, $this->deriveBasePathForTest($base . '/current'));
    }

    // -----------------------------------------------------------------------
    // Sécurité — traversal de chemin rejeté par le contrôleur (couvert ici
    // au niveau service : archive name avec / → archive_missing)
    // -----------------------------------------------------------------------

    public function testActivateWithPathTraversalReturnsArchiveMissing(): void
    {
        $service = $this->makeService();
        $result  = $service->activate('../etc/passwd');

        // Service ne sort pas du staging/ — le fichier n'existe pas → archive_missing
        $this->assertFalse($result['ok']);
        $this->assertSame('archive_missing', $result['error']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Retourne un service avec NullLockStrategy (pas de DB requise pour le lock).
     */
    private function makeService(): ReleaseActivationService
    {
        return new ReleaseActivationService(null, $this->tmpBase, new NullLockStrategy());
    }

    private function deriveBasePathForTest(string $rootPath): string
    {
        $service = $this->makeService();
        $method = new ReflectionMethod(ReleaseActivationService::class, 'deriveBasePath');
        $method->setAccessible(true);

        return $method->invoke($service, $rootPath);
    }

    /**
     * Crée une archive .tar.gz valide (avec les 4 dossiers requis) dans staging/.
     */
    private function createValidArchive(): string
    {
        return $this->createArchiveWithDirs(['app', 'vendor', 'public', 'database/migrations_sql']);
    }

    /**
     * Crée une archive .tar.gz contenant exactement les dossiers fournis.
     * Dépose l'archive et son .sha256 dans staging/.
     * Retourne le nom du fichier archive.
     */
    private function createArchiveWithDirs(array $dirs): string
    {
        $sourceDir = sys_get_temp_dir() . '/kermesse_src_' . uniqid('', true);

        foreach ($dirs as $dir) {
            mkdir($sourceDir . '/' . ltrim($dir, '/'), 0755, true);
        }

        $archiveName = 'kermesse-deploy.tar.gz';
        $archivePath = $this->tmpBase . '/staging/' . $archiveName;

        exec('tar -czf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($sourceDir) . ' . 2>&1', $out, $code);

        if ($code !== 0) {
            $this->fail('Could not create test archive: ' . implode("\n", $out));
        }

        $checksum = hash_file('sha256', $archivePath);
        file_put_contents($archivePath . '.sha256', $checksum);

        $this->removeDirRecursive($sourceDir);

        return $archiveName;
    }

}
