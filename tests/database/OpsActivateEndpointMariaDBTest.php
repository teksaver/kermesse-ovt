<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

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
    use TmpDirTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';
    private string $tmpBase;
    private \Config\Kermesse $originalConfig;

    protected $DBGroup = 'tests';

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

        $config->opsMigrationProductionOnly = false;
        $config->opsMigrationHmacSecret     = $this->testSecret;
        $config->opsMigrationAllowedTimestampSkew = 300;
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

        $result = $this->withHeaders($this->buildValidHeaders($jsonBody))
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

        $result = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('checksum_mismatch', $json['error']);
    }

    public function testEmptyArchiveNameReturns422(): void
    {
        $jsonBody = json_encode(['archive' => '']);

        $result = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    public function testPathTraversalInArchiveNameReturns422(): void
    {
        $jsonBody = json_encode(['archive' => '../etc/passwd']);

        $result = $this->withHeaders($this->buildValidHeaders($jsonBody))
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

        $result = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(200);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertNotEmpty($json['release']);
        $this->assertIsInt($json['pruned']);
    }

    /**
     * @return array<string, string>
     */
    private function buildValidHeaders(string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/activate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        return [
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ];
    }

    private function createValidArchive(): string
    {
        $sourceDir = sys_get_temp_dir() . '/kermesse_feat_src_' . uniqid('', true);

        foreach (['app', 'vendor', 'public', 'database/migrations_sql'] as $dir) {
            mkdir($sourceDir . '/' . $dir, 0755, true);
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
