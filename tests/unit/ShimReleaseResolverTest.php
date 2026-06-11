<?php

/**
 * Tests for the Kermesse deployment shim release resolution logic.
 *
 * The shim (deploy/shim/index.php) must resolve the active release via two methods:
 *   1. Primary:  kermesse/current symlink pointing to releases/<name>
 *   2. Fallback: kermesse/CURRENT_RELEASE text file containing the release folder name
 *
 * Tests run without booting CodeIgniter (KERMESSE_SHIM_TESTING guard is set).
 *
 * @internal
 * @runTestsInSeparateProcesses
 */
final class ShimReleaseResolverTest extends \PHPUnit\Framework\TestCase
{
    private string $tmpDir;
    private string $kermDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Simulate the Ouvaton home layout:
        //   <tmp>/httpdocs/   — web root (shim lives here)
        //   <tmp>/kermesse/   — private app dir
        $this->tmpDir = sys_get_temp_dir() . '/kermesse_shim_test_' . uniqid('', true);
        $this->kermDir = $this->tmpDir . '/kermesse';

        mkdir($this->tmpDir . '/httpdocs', 0755, true);
        mkdir($this->kermDir . '/releases', 0755, true);
        mkdir($this->kermDir . '/shared/writable', 0755, true);

        if (!defined('KERMESSE_SHIM_TESTING')) {
            define('KERMESSE_SHIM_TESTING', true);
        }

        require_once __DIR__ . '/../../deploy/shim/index.php';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Method 1 — symlink `current`
    // -----------------------------------------------------------------------

    public function testFindsReleaseViaSymlink(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        $releaseDir = $this->kermDir . '/releases/20260607T100000Z-abc1234';
        mkdir($releaseDir, 0755, true);
        symlink($releaseDir, $this->kermDir . '/current');

        $result = shim_find_release_dir($this->kermDir);

        $this->assertNotFalse($result);
        $this->assertSame(realpath($releaseDir), $result);
    }

    public function testIgnoresBrokenSymlinkAndFallsBackToPointerFile(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        // Create a symlink pointing to a non-existent directory (broken)
        symlink($this->kermDir . '/releases/missing', $this->kermDir . '/current');

        // Set up the fallback pointer file
        $releaseDir = $this->kermDir . '/releases/20260607T110000Z-def5678';
        mkdir($releaseDir, 0755, true);
        file_put_contents($this->kermDir . '/CURRENT_RELEASE', '20260607T110000Z-def5678');

        $result = shim_find_release_dir($this->kermDir);

        $this->assertNotFalse($result);
        $this->assertSame(realpath($releaseDir), $result);
    }

    // -----------------------------------------------------------------------
    // Method 2 — CURRENT_RELEASE file fallback (no symlink at all)
    // -----------------------------------------------------------------------

    public function testFindsReleaseViaPointerFile(): void
    {
        $releaseDir = $this->kermDir . '/releases/20260607T120000Z-ghi9012';
        mkdir($releaseDir, 0755, true);
        file_put_contents($this->kermDir . '/CURRENT_RELEASE', '20260607T120000Z-ghi9012');

        $result = shim_find_release_dir($this->kermDir);

        $this->assertNotFalse($result);
        $this->assertSame(realpath($releaseDir), $result);
    }

    public function testPointerFileStripsWhitespace(): void
    {
        $releaseDir = $this->kermDir . '/releases/20260607T130000Z-jkl3456';
        mkdir($releaseDir, 0755, true);
        file_put_contents($this->kermDir . '/CURRENT_RELEASE', "  20260607T130000Z-jkl3456\n");

        $result = shim_find_release_dir($this->kermDir);

        $this->assertNotFalse($result);
        $this->assertSame(realpath($releaseDir), $result);
    }

    public function testReturnsFalseWhenPointerFilePointsToMissingRelease(): void
    {
        file_put_contents($this->kermDir . '/CURRENT_RELEASE', 'nonexistent-release');

        $result = shim_find_release_dir($this->kermDir);

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // No active release
    // -----------------------------------------------------------------------

    public function testReturnsFalseWhenNoSymlinkAndNoPointerFile(): void
    {
        $result = shim_find_release_dir($this->kermDir);

        $this->assertFalse($result);
    }

    public function testReturnsFalseWhenPointerFileIsEmpty(): void
    {
        file_put_contents($this->kermDir . '/CURRENT_RELEASE', '');

        $result = shim_find_release_dir($this->kermDir);

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                if ($item->isLink() || $item->isFile()) {
                    $path = $item->getRealPath() ?: $item->getPathname();
                    if (!@unlink($path) && file_exists($path)) {
                        @chmod($path, 0777);
                        @unlink($path);
                    }
                } elseif ($item->isDir()) {
                    $path = $item->getRealPath() ?: $item->getPathname();
                    if (!@rmdir($path) && is_dir($path)) {
                        @chmod($path, 0777);
                        @rmdir($path);
                    }
                }
            }

            if (is_dir($dir)) {
                if (!@rmdir($dir)) {
                    @chmod($dir, 0777);
                    @rmdir($dir);
                }
            }
        } catch (\UnexpectedValueException $e) {
            // Ignore access denied errors during teardown
        } catch (\Exception $e) {
            // Ignore other filesystem errors during teardown
        }
    }
}
