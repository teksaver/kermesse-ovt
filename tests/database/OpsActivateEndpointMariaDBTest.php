<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/OpsTestHelperTrait.php';
require_once __DIR__ . '/../_support/TmpDirTrait.php';

/**
 * MariaDB-backed feature tests for POST /ops/activate.
 *
 * A valid HMAC request consumes a nonce (OpsAuthFilter writes to ops_nonces)
 * and ReleaseActivationService uses a MariaDB named lock, so these tests
 * require a real MariaDB connection.
 *
 * @group mariadb
 * @internal
 */
final class OpsActivateEndpointMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use OpsTestHelperTrait;
    use TmpDirTrait;

    private string $tmpBase;
    private \Config\Kermesse $originalConfig;

    protected $DBGroup  = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('MariaDB activation endpoint tests must run with database.tests.DBDriver=MySQLi in CI.');
            }

            $this->markTestSkipped('These tests require a MariaDB/MySQL connection (database.tests.DBDriver=MySQLi).');
        }

        $db->query('DROP TABLE IF EXISTS `ops_nonces`');

        $this->tmpBase = sys_get_temp_dir() . '/kermesse_feat_activate_' . uniqid('', true);

        mkdir($this->tmpBase . '/staging',  0755, true);
        mkdir($this->tmpBase . '/releases', 0755, true);

        $config = config('Kermesse');
        $this->originalConfig = clone $config;

        $this->setUpOpsConfig();
        $config->opsActivateBasePath        = $this->tmpBase;
        $config->opsActivateLockName        = 'kermesse_ops_activate_lock_feat_test_' . uniqid();
        $config->releasesRetention          = 3;
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpBase) && is_dir($this->tmpBase)) {
            $this->removeDirRecursive($this->tmpBase);
        }

        $config = config('Kermesse');
        foreach (get_object_vars($this->originalConfig) as $key => $value) {
            $config->$key = $value;
        }

        parent::tearDown();
    }

    public function testMissingArchiveReturns422(): void
    {
        $jsonBody = json_encode(['archive' => 'kermesse-deploy.tar.gz']);

        $result = $this->withHeaders($this->buildOpsHeaders('ops/activate', $jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    public function testChecksumMismatchReturns422(): void
    {
        $archivePath = $this->tmpBase . '/staging/kermesse-deploy.tar.gz';
        file_put_contents($archivePath, 'archive content');
        file_put_contents($archivePath . '.sha256', 'wrong_sha256_value_here');

        $jsonBody = json_encode(['archive' => 'kermesse-deploy.tar.gz']);

        $result = $this->withHeaders($this->buildOpsHeaders('ops/activate', $jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('checksum_mismatch', $json['error']);
    }

    public function testEmptyArchiveNameReturns422(): void
    {
        $jsonBody = json_encode(['archive' => '']);

        $result = $this->withHeaders($this->buildOpsHeaders('ops/activate', $jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    public function testPathTraversalInArchiveNameReturns422(): void
    {
        $jsonBody = json_encode(['archive' => '../etc/passwd']);

        $result = $this->withHeaders($this->buildOpsHeaders('ops/activate', $jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    public function testSuccessfulActivationReturns200(): void
    {
        $archiveName = $this->createValidArchive();
        $jsonBody    = json_encode(['archive' => $archiveName]);

        $result = $this->withHeaders($this->buildOpsHeaders('ops/activate', $jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(200);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertNotEmpty($json['release']);
        $this->assertIsInt($json['pruned']);
    }

    private function createValidArchive(): string
    {
        $archiveName = 'kermesse-deploy.tar.gz';
        $archivePath = $this->tmpBase . '/staging/' . $archiveName;
        $tarPath = substr($archivePath, 0, -3);

        $archive = new PharData($tarPath);
        foreach (['app', 'vendor', 'public', 'database/migrations_sql'] as $dir) {
            $archive->addFromString($dir . '/.keep', '');
        }
        $archive->compress(Phar::GZ);
        unset($archive);
        unlink($tarPath);

        $checksum = hash_file('sha256', $archivePath);
        file_put_contents($archivePath . '.sha256', $checksum);

        return $archiveName;
    }
}
