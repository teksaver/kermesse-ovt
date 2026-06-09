<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Kermesse;

/**
 * Applies SQL migration files from database/migrations_sql/ in lexical order.
 *
 * - Bootstraps schema_versions and ops_nonces tables if absent.
 * - Acquires a MariaDB named lock before applying migrations.
 * - Records version, checksum, status and timing in schema_versions.
 * - Refuses to apply a migration whose checksum has changed since it was last applied successfully.
 */
class MigrationRunnerService
{
    private BaseConnection $db;
    private string $migrationsPath;
    private string $lockName;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();

        $config = config(Kermesse::class);
        $this->lockName = $config->opsMigrationLockName;

        $this->migrationsPath = $config->opsMigrationPath !== ''
            ? $config->opsMigrationPath
            : ROOTPATH . 'database/migrations_sql';
    }

    /**
     * Read-only status check: classify all discovered migrations without applying anything or acquiring a lock.
     *
     * @return array{ok: bool, pending: list<string>, applied: list<string>, failed: list<string>}
     */
    public function status(): array
    {
        $result = [
            'ok'      => true,
            'pending' => [],
            'applied' => [],
            'failed'  => [],
        ];

        $migrations = $this->discoverMigrations();

        try {
            $appliedVersions = $this->getAppliedVersions();
        } catch (\Throwable $e) {
            // DB non initialisée (schema_versions n'existe pas) -> 0 migration appliquée
            $code = $e->getCode();
            if ($code === 1146 || $code === 1 || strpos($e->getMessage(), 'schema_versions') !== false) {
                $appliedVersions = [];
            } else {
                throw $e;
            }
        }

        foreach ($migrations as $migration) {
            $version = $migration['version'];

            if (!isset($appliedVersions[$version])) {
                $result['pending'][] = $version;
                continue;
            }

            $dbStatus = $appliedVersions[$version]['status'];

            if ($dbStatus === 'success') {
                $result['applied'][] = $version;
            } elseif ($dbStatus === 'failed') {
                $result['failed'][] = $version;
            } else {
                $result['pending'][] = $version;
            }
        }

        return $result;
    }

    /**
     * Run all pending migrations and return a summary.
     *
     * @return array{ok: bool, applied: list<string>, skipped: list<string>, failed: list<string>}
     */
    public function run(): array
    {
        $result = [
            'ok'      => true,
            'applied' => [],
            'skipped' => [],
            'failed'  => [],
        ];

        // Bootstrap technical tables on a blank database
        $this->bootstrapTechnicalTables();

        // Acquire named lock
        if (!$this->acquireLock()) {
            $result['ok'] = false;
            $result['failed'][] = 'lock_acquisition_failed';
            return $result;
        }

        try {
            $migrations = $this->discoverMigrations();
            $appliedVersions = $this->getAppliedVersions();

            foreach ($migrations as $migration) {
                $version  = $migration['version'];
                $checksum = $migration['checksum'];
                $filePath = $migration['path'];

                // Already applied successfully?
                if (isset($appliedVersions[$version])) {
                    $existing = $appliedVersions[$version];

                    if ($existing['status'] === 'success') {
                        // Checksum mismatch on a successful migration → refuse
                        if ($existing['checksum'] !== $checksum) {
                            $result['ok'] = false;
                            $result['failed'][] = $version;
                            $this->recordDriftDetected(
                                $version,
                                'Checksum mismatch: migration file changed after successful application'
                            );
                            break; // Stop on first failure
                        }

                        $result['skipped'][] = $version;
                        continue;
                    }

                    // Previously failed → retry allowed
                }

                // Apply migration
                $applyResult = $this->applyMigration($version, $checksum, $filePath);

                if ($applyResult['ok']) {
                    $result['applied'][] = $version;
                } else {
                    $result['ok'] = false;
                    $result['failed'][] = $version;
                    break; // Stop on first failure
                }
            }
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    /**
     * Ensure schema_versions and ops_nonces exist (idempotent bootstrap).
     */
    private function bootstrapTechnicalTables(): void
    {
        $bootstrapSql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `schema_versions` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `version`            VARCHAR(255)    NOT NULL,
                `checksum`           VARCHAR(64)     NOT NULL,
                `status`             ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
                `applied_at`         DATETIME        NULL     DEFAULT NULL,
                `execution_time_ms`  INT UNSIGNED    NULL     DEFAULT NULL,
                `error_code`         VARCHAR(10)     NULL     DEFAULT NULL,
                `error_message`      TEXT            NULL     DEFAULT NULL,
                `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_schema_versions_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            SQL;

        // Use the same cross-database DDL as OpsAuthFilter::bootstrapNonceTable().
        // The old schema with AUTO_INCREMENT `id` has been superseded by migration
        // 20260607183000_update_ops_nonces_schema.sql. Keeping this DDL aligned avoids
        // an unnecessary DROP+CREATE on fresh installs where bootstrapTechnicalTables()
        // runs before the migration. nonce_hash is the natural key and the PRIMARY KEY
        // used for duplicate-INSERT replay detection.
        $nonceSql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS ops_nonces (
                nonce_hash   VARCHAR(64)  NOT NULL,
                expires_at   DATETIME     NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (nonce_hash)
            )
            SQL;

        $this->db->query($bootstrapSql);
        $this->db->query($nonceSql);

        $indexExists = $this->db->query("SHOW INDEX FROM ops_nonces WHERE Key_name = 'idx_ops_nonces_expires'")->getNumRows() > 0;
        if (!$indexExists) {
            $this->db->query('CREATE INDEX idx_ops_nonces_expires ON ops_nonces (expires_at)');
        }
    }

    /**
     * Discover SQL migration files, sorted in lexical order.
     *
     * @return list<array{version: string, checksum: string, path: string}>
     */
    protected function discoverMigrations(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files, SORT_STRING);

        $migrations = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath, '.sql');
            $content  = file_get_contents($filePath);

            if ($content === false) {
                continue;
            }

            $checksum = hash('sha256', $content);

            $migrations[] = [
                'version'  => $filename,
                'checksum' => $checksum,
                'path'     => $filePath,
            ];
        }

        return $migrations;
    }

    /**
     * Mark drift without overwriting the successful checksum that was applied.
     */
    private function recordDriftDetected(string $version, string $errorMessage): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'UPDATE `schema_versions`
             SET `error_code` = ?, `error_message` = ?, `updated_at` = ?
             WHERE `version` = ? AND `status` = ?',
            ['DRIFT', $errorMessage, $now, $version, 'success']
        );
    }

    /**
     * Get all previously applied versions from schema_versions.
     *
     * @return array<string, array{checksum: string, status: string}>
     */
    protected function getAppliedVersions(): array
    {
        $query = $this->db->query(
            'SELECT `version`, `checksum`, `status` FROM `schema_versions` ORDER BY `id` ASC'
        );

        $versions = [];

        foreach ($query->getResultArray() as $row) {
            $versions[$row['version']] = [
                'checksum' => $row['checksum'],
                'status'   => $row['status'],
            ];
        }

        return $versions;
    }

    /**
     * Apply a single SQL migration file.
     *
     * @return array{ok: bool}
     */
    private function applyMigration(string $version, string $checksum, string $filePath): array
    {
        $sql = file_get_contents($filePath);

        if ($sql === false || trim($sql) === '') {
            $this->recordMigration($version, $checksum, 'failed', 0, 'EMPTY', 'Migration file is empty or unreadable');
            return ['ok' => false];
        }

        $startTime = hrtime(true);

        try {
            // Split multi-statement SQL and execute each statement
            $statements = $this->splitStatements($sql);

            foreach ($statements as $statement) {
                if (trim($statement) === '') {
                    continue;
                }

                $this->db->query($statement);
            }

            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->recordMigration($version, $checksum, 'success', $executionTimeMs);

            return ['ok' => true];
        } catch (\Throwable $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Log the full error internally
            log_message('error', 'MigrationRunner: migration {version} failed: {message}', [
                'version' => $version,
                'message' => $e->getMessage(),
            ]);

            // Record sanitised error in schema_versions
            $errorCode = 'SQL';
            if ($e instanceof \CodeIgniter\Database\Exceptions\DatabaseException) {
                $errorCode = 'DBEXC';
            }

            $this->recordMigration(
                $version,
                $checksum,
                'failed',
                $executionTimeMs,
                $errorCode,
                'Migration execution failed' // Sanitised — no raw SQL or stack trace
            );

            return ['ok' => false];
        }
    }

    /**
     * Record a migration result in schema_versions (upsert).
     */
    private function recordMigration(
        string $version,
        string $checksum,
        string $status,
        int $executionTimeMs,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {
        // Check if version already exists
        $existing = $this->db->query(
            'SELECT `id` FROM `schema_versions` WHERE `version` = ?',
            [$version]
        )->getRowArray();

        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $this->db->query(
                'UPDATE `schema_versions`
                 SET `checksum` = ?, `status` = ?, `applied_at` = ?,
                     `execution_time_ms` = ?, `error_code` = ?, `error_message` = ?,
                     `updated_at` = ?
                 WHERE `id` = ?',
                [$checksum, $status, $now, $executionTimeMs, $errorCode, $errorMessage, $now, $existing['id']]
            );
        } else {
            $this->db->query(
                'INSERT INTO `schema_versions`
                 (`version`, `checksum`, `status`, `applied_at`, `execution_time_ms`, `error_code`, `error_message`, `created_at`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$version, $checksum, $status, $now, $executionTimeMs, $errorCode, $errorMessage, $now, $now]
            );
        }
    }

    /**
     * Split a SQL string into individual statements on semicolons.
     *
     * This is a simple splitter suitable for DDL migrations.
     * It does not handle stored procedures with delimiters.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote === null && $char === '-' && $next === '-') {
                $previous = $i > 0 ? $sql[$i - 1] : "\n";
                $after = $i + 2 < $length ? $sql[$i + 2] : '';

                if (($previous === "\n" || ctype_space($previous)) && ($after === '' || ctype_space($after))) {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
            }

            if ($quote === null && $char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($quote === null && $char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    if (($quote === '\'' || $quote === '"') && $next === $quote) {
                        $current .= $next;
                        $i++;
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statement = trim($current);

        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * Acquire a MariaDB named lock.
     */
    private function acquireLock(): bool
    {
        $result = $this->db->query(
            'SELECT GET_LOCK(?, 10) AS `acquired`',
            [$this->lockName]
        )->getRowArray();

        return ($result['acquired'] ?? 0) == 1;
    }

    /**
     * Release the MariaDB named lock.
     */
    private function releaseLock(): void
    {
        $this->db->query(
            'SELECT RELEASE_LOCK(?) AS `released`',
            [$this->lockName]
        );
    }
}
