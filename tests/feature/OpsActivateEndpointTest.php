<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/TmpDirTrait.php';

/**
 * Feature tests for POST /ops/activate endpoint.
 *
 * These tests cover HTTP-level behaviour: auth rejection, JSON shape, error codes,
 * and sanitisation. Tests that require a real MariaDB named lock are @group mariadb.
 *
 * @internal
 */
final class OpsActivateEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use TmpDirTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir() . '/kermesse_feat_activate_' . uniqid('', true);

        mkdir($this->tmpBase . '/staging',  0755, true);
        mkdir($this->tmpBase . '/releases', 0755, true);

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationHmacSecret     = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = 300;
        config('Kermesse')->opsActivateBasePath         = $this->tmpBase;
        config('Kermesse')->opsActivateLockName         = 'kermesse_ops_activate_lock_feat_test';
        config('Kermesse')->releasesRetention           = 3;
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmpBase);
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Auth — requête sans signature → 403
    // -----------------------------------------------------------------------

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $result = $this->post('ops/activate', ['archive' => 'kermesse-deploy.tar.gz']);

        $result->assertStatus(403);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('ops_unauthorized', $json['error']);
    }

    // -----------------------------------------------------------------------
    // Auth — signature pour ops/migrate rejouée sur ops/activate → 403
    // -----------------------------------------------------------------------

    public function testCrossRouteReplayIsRejected(): void
    {
        $body      = json_encode(['archive' => 'kermesse-deploy.tar.gz']);
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/activate', json_decode($body, true));

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Sanitisation — la réponse de rejet ne contient pas de secrets ou SQL
    // -----------------------------------------------------------------------

    public function testRejectedResponseDoesNotLeakSensitiveData(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'sanitize-test',
            'X-Kermesse-Signature' => 'bad-sig',
        ])->post('ops/activate', ['archive' => 'kermesse-deploy.tar.gz']);

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('CREATE TABLE', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString($this->testSecret, $body);
    }

    // -----------------------------------------------------------------------
    // AC-2 — Archive absente → 422 archive_missing
    // -----------------------------------------------------------------------

    public function testMissingArchiveReturns422(): void
    {
        // staging/ is empty — no archive
        $jsonBody = json_encode(['archive' => 'kermesse-deploy.tar.gz']);
        $result   = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    // -----------------------------------------------------------------------
    // AC-3 — Checksum incorrect → 422 checksum_mismatch
    // -----------------------------------------------------------------------

    public function testChecksumMismatchReturns422(): void
    {
        $archivePath = $this->tmpBase . '/staging/kermesse-deploy.tar.gz';
        file_put_contents($archivePath, 'archive content');
        file_put_contents($archivePath . '.sha256', 'wrong_sha256_value_here');

        $jsonBody = json_encode(['archive' => 'kermesse-deploy.tar.gz']);
        $result   = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('checksum_mismatch', $json['error']);
    }

    // -----------------------------------------------------------------------
    // Corps invalide — archive vide ou traversal → 422 archive_missing
    // -----------------------------------------------------------------------

    public function testEmptyArchiveNameReturns422(): void
    {
        $jsonBody = json_encode(['archive' => '']);
        $result   = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    public function testPathTraversalInArchiveNameReturns422(): void
    {
        $jsonBody = json_encode(['archive' => '../etc/passwd']);
        $result   = $this->withHeaders($this->buildValidHeaders($jsonBody))
            ->withBody($jsonBody)
            ->post('ops/activate');

        $result->assertStatus(422);
        $json = json_decode($result->response()->getBody(), true);
        $this->assertSame('archive_missing', $json['error']);
    }

    // -----------------------------------------------------------------------
    // AC-1 — Activation réussie → 200 {"ok":true,"release":"...","pruned":N}
    // -----------------------------------------------------------------------

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

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Construit les en-têtes HMAC valides pour un corps donné.
     *
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

    /**
     * Crée une archive .tar.gz valide avec les 4 dossiers requis.
     */
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
