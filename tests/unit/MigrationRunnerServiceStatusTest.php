<?php

use App\Services\MigrationRunnerService;
use CodeIgniter\Test\CIUnitTestCase;

require_once __DIR__ . '/../_support/TmpDirTrait.php';

/**
 * Unit tests for MigrationRunnerService::status() classification logic.
 *
 * Uses an anonymous subclass overriding discoverMigrations() and getAppliedVersions()
 * so that no real DB or filesystem is required.
 *
 * @internal
 */
final class MigrationRunnerServiceStatusTest extends CIUnitTestCase
{
    use TmpDirTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/kermesse_status_unit_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationPath = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a stub service with controlled discover/applied data.
     *
     * @param list<array{version: string, checksum: string, path: string}> $discovered
     * @param array<string, array{checksum: string, status: string}> $applied
     */
    private function makeStub(array $discovered, array $applied): MigrationRunnerService
    {
        return new class ($discovered, $applied) extends MigrationRunnerService {
            private array $stubbedDiscovered;
            private array $stubbedApplied;

            public function __construct(array $discovered, array $applied)
            {
                $this->stubbedDiscovered = $discovered;
                $this->stubbedApplied    = $applied;
                // Skip parent constructor to avoid db_connect() — status() only needs the two protected methods.
            }

            protected function discoverMigrations(): array
            {
                return $this->stubbedDiscovered;
            }

            protected function getAppliedVersions(): array
            {
                return $this->stubbedApplied;
            }
        };
    }

    // -----------------------------------------------------------------------
    // AC-1: réponse structurée avec ok:true et les trois clés
    // -----------------------------------------------------------------------

    public function testStatusReturnsStructuredResponse(): void
    {
        $stub   = $this->makeStub([], []);
        $result = $stub->status();

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('pending', $result);
        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertTrue($result['ok']);
    }

    // -----------------------------------------------------------------------
    // Classification : migration absente de schema_versions → pending
    // -----------------------------------------------------------------------

    public function testMigrationNotInAppliedVersionsIsPending(): void
    {
        $stub = $this->makeStub(
            [['version' => '20260601_alpha', 'checksum' => 'abc', 'path' => '/tmp/a.sql']],
            []
        );

        $result = $stub->status();

        $this->assertContains('20260601_alpha', $result['pending']);
        $this->assertEmpty($result['applied']);
        $this->assertEmpty($result['failed']);
    }

    // -----------------------------------------------------------------------
    // Classification : status 'success' → applied
    // -----------------------------------------------------------------------

    public function testMigrationWithSuccessStatusIsApplied(): void
    {
        $stub = $this->makeStub(
            [['version' => '20260601_alpha', 'checksum' => 'abc', 'path' => '/tmp/a.sql']],
            ['20260601_alpha' => ['checksum' => 'abc', 'status' => 'success']]
        );

        $result = $stub->status();

        $this->assertContains('20260601_alpha', $result['applied']);
        $this->assertEmpty($result['pending']);
        $this->assertEmpty($result['failed']);
    }

    // -----------------------------------------------------------------------
    // Classification : status 'failed' → failed
    // -----------------------------------------------------------------------

    public function testMigrationWithFailedStatusIsFailed(): void
    {
        $stub = $this->makeStub(
            [['version' => '20260601_alpha', 'checksum' => 'abc', 'path' => '/tmp/a.sql']],
            ['20260601_alpha' => ['checksum' => 'abc', 'status' => 'failed']]
        );

        $result = $stub->status();

        $this->assertContains('20260601_alpha', $result['failed']);
        $this->assertEmpty($result['pending']);
        $this->assertEmpty($result['applied']);
    }

    // -----------------------------------------------------------------------
    // Classification : status 'pending' dans schema_versions → pending (état intermédiaire)
    // -----------------------------------------------------------------------

    public function testMigrationWithPendingDbStatusIsPending(): void
    {
        $stub = $this->makeStub(
            [['version' => '20260601_alpha', 'checksum' => 'abc', 'path' => '/tmp/a.sql']],
            ['20260601_alpha' => ['checksum' => 'abc', 'status' => 'pending']]
        );

        $result = $stub->status();

        $this->assertContains('20260601_alpha', $result['pending']);
        $this->assertEmpty($result['applied']);
        $this->assertEmpty($result['failed']);
    }

    // -----------------------------------------------------------------------
    // Classification mixte : plusieurs migrations dans des états différents
    // -----------------------------------------------------------------------

    public function testMixedMigrationsAreClassifiedCorrectly(): void
    {
        $stub = $this->makeStub(
            [
                ['version' => '20260601_001', 'checksum' => 'c1', 'path' => '/tmp/1.sql'],
                ['version' => '20260601_002', 'checksum' => 'c2', 'path' => '/tmp/2.sql'],
                ['version' => '20260601_003', 'checksum' => 'c3', 'path' => '/tmp/3.sql'],
                ['version' => '20260601_004', 'checksum' => 'c4', 'path' => '/tmp/4.sql'],
            ],
            [
                '20260601_001' => ['checksum' => 'c1', 'status' => 'success'],
                '20260601_002' => ['checksum' => 'c2', 'status' => 'failed'],
                // 20260601_003 absent → pending
                '20260601_004' => ['checksum' => 'c4', 'status' => 'pending'],
            ]
        );

        $result = $stub->status();

        $this->assertTrue($result['ok']);
        $this->assertContains('20260601_001', $result['applied']);
        $this->assertContains('20260601_002', $result['failed']);
        $this->assertContains('20260601_003', $result['pending']);
        $this->assertContains('20260601_004', $result['pending']);

        $this->assertCount(1, $result['applied']);
        $this->assertCount(1, $result['failed']);
        $this->assertCount(2, $result['pending']);
    }

    // -----------------------------------------------------------------------
    // AC-2 : état « en attente » n'est pas une erreur (ok reste true)
    // -----------------------------------------------------------------------

    public function testOkIsTrueEvenWhenMigrationsArePending(): void
    {
        $stub = $this->makeStub(
            [['version' => '20260601_pending', 'checksum' => 'x', 'path' => '/tmp/p.sql']],
            []
        );

        $result = $stub->status();

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['pending']);
    }

    // -----------------------------------------------------------------------
    // Pas de verrou : status() sur une liste vide renvoie des tableaux vides
    // -----------------------------------------------------------------------

    public function testEmptyMigrationsDirectoryReturnsEmptyArrays(): void
    {
        $stub   = $this->makeStub([], []);
        $result = $stub->status();

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['pending']);
        $this->assertEmpty($result['applied']);
        $this->assertEmpty($result['failed']);
    }
}
