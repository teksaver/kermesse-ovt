<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

require_once __DIR__ . '/../_support/TmpDirTrait.php';

/**
 * Feature tests for POST /ops/migrate/status endpoint.
 *
 * Tests that don't require a real DB cover authentication rejection.
 * Tests requiring MariaDB are tagged @group mariadb.
 *
 * @internal
 */
final class OpsMigrateStatusEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use TmpDirTrait;

    private string $testSecret = 'test_hmac_secret_32_bytes_minimum_value';
    private ?string $tmpDir = null;

    private bool $originalOpsMigrationProductionOnly;
    private string $originalOpsMigrationHmacSecret;
    private int $originalOpsMigrationAllowedTimestampSkew;
    private string $originalOpsMigrationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalOpsMigrationProductionOnly       = config('Kermesse')->opsMigrationProductionOnly;
        $this->originalOpsMigrationHmacSecret           = config('Kermesse')->opsMigrationHmacSecret;
        $this->originalOpsMigrationAllowedTimestampSkew = config('Kermesse')->opsMigrationAllowedTimestampSkew;
        $this->originalOpsMigrationPath                 = config('Kermesse')->opsMigrationPath;

        config('Kermesse')->opsMigrationProductionOnly        = false;
        config('Kermesse')->opsMigrationHmacSecret            = $this->testSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew  = 300;
    }

    protected function tearDown(): void
    {
        config('Kermesse')->opsMigrationProductionOnly       = $this->originalOpsMigrationProductionOnly;
        config('Kermesse')->opsMigrationHmacSecret           = $this->originalOpsMigrationHmacSecret;
        config('Kermesse')->opsMigrationAllowedTimestampSkew = $this->originalOpsMigrationAllowedTimestampSkew;
        config('Kermesse')->opsMigrationPath                 = $this->originalOpsMigrationPath;

        if ($this->tmpDir !== null) {
            $this->removeDirRecursive($this->tmpDir);
            $this->tmpDir = null;
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Auth — requête sans signature → 403
    // -----------------------------------------------------------------------

    public function testRequestWithoutHmacIsRejected(): void
    {
        $result = $this->post('ops/migrate/status');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Auth — signature invalide → 403
    // -----------------------------------------------------------------------

    public function testRequestWithBadSignatureIsRejected(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => bin2hex(random_bytes(16)),
            'X-Kermesse-Signature' => 'bad-signature',
        ])->post('ops/migrate/status');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Auth — signature valide pour ops/migrate rejouée sur ops/migrate/status → 403
    // -----------------------------------------------------------------------

    public function testCrossRouteReplayIsRejected(): void
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', '');
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/migrate/status');

        $result->assertStatus(403);
        $result->assertJSONExact(['error' => 'ops_unauthorized']);
    }

    // -----------------------------------------------------------------------
    // Sanitisation — la réponse de rejet ne logue pas de données sensibles
    // -----------------------------------------------------------------------

    public function testRejectedResponseDoesNotContainSensitiveData(): void
    {
        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => (string) time(),
            'X-Kermesse-Nonce'     => 'sanitize-test',
            'X-Kermesse-Signature' => 'bad-sig',
        ])->post('ops/migrate/status');

        $body = $result->response()->getBody();

        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('stack trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString($this->testSecret, $body);
    }

    // -----------------------------------------------------------------------
    // AC-1 + AC-2 : HMAC valide → 200 avec les trois clés (nécessite MariaDB)
    //
    // @group mariadb
    // -----------------------------------------------------------------------

    /**
     * @group mariadb
     */
    public function testValidHmacReturns200WithExpectedShape(): void
    {
        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            $this->markTestSkipped('Ce test requiert une connexion MariaDB/MySQL (database.tests.DBDriver=MySQLi).');
        }

        // Bootstrap schema_versions si absent
        $db->query(
            'CREATE TABLE IF NOT EXISTS `schema_versions` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `version`            VARCHAR(255)    NOT NULL,
                `checksum`           VARCHAR(64)     NOT NULL,
                `status`             ENUM(\'pending\',\'success\',\'failed\') NOT NULL DEFAULT \'pending\',
                `applied_at`         DATETIME        NULL     DEFAULT NULL,
                `execution_time_ms`  INT UNSIGNED    NULL     DEFAULT NULL,
                `error_code`         VARCHAR(10)     NULL     DEFAULT NULL,
                `error_message`      TEXT            NULL     DEFAULT NULL,
                `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_schema_versions_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        // Dossier de migrations vide → tout est pending (aucune migration découverte)
        $this->tmpDir = sys_get_temp_dir() . '/kermesse_status_feat_empty_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        config('Kermesse')->opsMigrationPath = $this->tmpDir;

        $body      = '';
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate/status', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/migrate/status');

        $result->assertStatus(200);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertArrayHasKey('pending', $json);
        $this->assertArrayHasKey('applied', $json);
        $this->assertArrayHasKey('failed', $json);
        $this->assertIsArray($json['pending']);
        $this->assertIsArray($json['applied']);
        $this->assertIsArray($json['failed']);
    }

    // -----------------------------------------------------------------------
    // AC-2 : l'état « en attente » renvoie bien 200 et non une erreur
    //
    // @group mariadb
    // -----------------------------------------------------------------------

    /**
     * @group mariadb
     */
    public function testPendingMigrationsStillReturn200(): void
    {
        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            $this->markTestSkipped('Ce test requiert une connexion MariaDB/MySQL (database.tests.DBDriver=MySQLi).');
        }

        $this->tmpDir = sys_get_temp_dir() . '/kermesse_status_feat_pending_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Écrire une migration qui n'a pas encore été appliquée
        file_put_contents($this->tmpDir . '/20260601000000_pending_test.sql', 'SELECT 1;');

        $db->query(
            'CREATE TABLE IF NOT EXISTS `schema_versions` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `version`            VARCHAR(255)    NOT NULL,
                `checksum`           VARCHAR(64)     NOT NULL,
                `status`             ENUM(\'pending\',\'success\',\'failed\') NOT NULL DEFAULT \'pending\',
                `applied_at`         DATETIME        NULL     DEFAULT NULL,
                `execution_time_ms`  INT UNSIGNED    NULL     DEFAULT NULL,
                `error_code`         VARCHAR(10)     NULL     DEFAULT NULL,
                `error_message`      TEXT            NULL     DEFAULT NULL,
                `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_schema_versions_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        config('Kermesse')->opsMigrationPath = $this->tmpDir;

        $body      = '';
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate/status', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        $result = $this->withHeaders([
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ])->post('ops/migrate/status');

        $result->assertStatus(200);
        $json = json_decode($result->response()->getBody(), true);

        $this->assertTrue($json['ok']);
        $this->assertContains('20260601000000_pending_test', $json['pending']);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function buildValidHeaders(string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $bodyHash  = hash('sha256', $body);
        $payload   = implode("\n", [$timestamp, $nonce, 'POST', 'ops/migrate/status', $bodyHash]);
        $signature = hash_hmac('sha256', $payload, $this->testSecret);

        return [
            'X-Kermesse-Timestamp' => $timestamp,
            'X-Kermesse-Nonce'     => $nonce,
            'X-Kermesse-Signature' => $signature,
        ];
    }
}
