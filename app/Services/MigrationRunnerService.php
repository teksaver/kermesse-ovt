<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Kermesse;

/**
 * Applies SQL migration files from database/migrations_sql/ in lexical order.
 *
 * - Bootstraps schema_versions and ops_nonces tables if absent.
 * - Acquires a named lock (via DatabaseLockStrategy) before applying migrations.
 * - Records version, checksum, status and timing in schema_versions.
 * - Refuses to apply a migration whose checksum has changed since it was last applied successfully.
 */
class MigrationRunnerService
{
    private BaseConnection $db;
    private DatabaseLockStrategy $lockStrategy;
    private string $migrationsPath;
    private string $lockName;

    public function __construct(?BaseConnection $db = null, ?DatabaseLockStrategy $lockStrategy = null)
    {
        $this->db = $db ?? db_connect();

        // Auto-detect lock strategy from driver when not explicitly provided.
        $this->lockStrategy = $lockStrategy ?? $this->buildLockStrategy($this->db);

        $config = config(Kermesse::class);
        $this->lockName = $config->opsMigrationLockName;

        $this->migrationsPath = $config->opsMigrationPath !== ''
            ? $config->opsMigrationPath
            : ROOTPATH . 'database/migrations_sql';
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
        return $this->executeMigrations(null);
    }

    /**
     * Run pending migrations up to and including $untilVersion (lexical order).
     *
     * Used to split the expand/contract pipeline into two phases:
     *   Phase 1: runUpTo('20260619500000_create_slot_signups_compat_view') — before new code is activated
     *   Phase 2: run() — after the new code is live and the compat view is validated
     *
     * Migrations with a version key > $untilVersion (string comparison) are not applied.
     * If $untilVersion is not discovered among the SQL files, the method applies nothing
     * and returns ok=false with error 'until_version_not_found'.
     *
     * @return array{ok: bool, applied: list<string>, skipped: list<string>, failed: list<string>}
     */
    public function runUpTo(string $untilVersion): array
    {
        return $this->executeMigrations($untilVersion);
    }

    /**
     * Core migration loop shared by run() and runUpTo().
     *
     * @param  string|null $untilVersion  Stop after applying/skipping this version; null = run all.
     * @return array{ok: bool, applied: list<string>, skipped: list<string>, failed: list<string>}
     */
    private function executeMigrations(?string $untilVersion): array
    {
        $result = [
            'ok'      => true,
            'applied' => [],
            'skipped' => [],
            'failed'  => [],
        ];

        // Bootstrap technical tables on a blank database
        $this->bootstrapTechnicalTables();

        // Acquire named lock via strategy (MariaDB GET_LOCK or NullLock for SQLite)
        if (!$this->lockStrategy->acquire($this->lockName, 10)) {
            $result['ok'] = false;
            $result['failed'][] = 'lock_acquisition_failed';
            return $result;
        }

        try {
            $migrations      = $this->discoverMigrations();
            $appliedVersions = $this->getAppliedVersions();

            // When a target version is specified, verify it exists in the discovered set
            // before starting — prevents silent no-ops from a typo in until_version.
            if ($untilVersion !== null) {
                $knownVersions = array_column($migrations, 'version');
                if (!in_array($untilVersion, $knownVersions, true)) {
                    $result['ok'] = false;
                    $result['failed'][] = 'until_version_not_found';
                    return $result;
                }
            }

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
                    }
                    // Previously failed → fall through to applyMigration (retry)
                    else {
                        $applyResult = $this->applyMigration($version, $checksum, $filePath);
                        if ($applyResult['ok']) {
                            $result['applied'][] = $version;
                        } else {
                            $result['ok'] = false;
                            $result['failed'][] = $version;
                            break;
                        }
                    }
                } else {
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

                // Stop after the target version when running a phased migration.
                if ($untilVersion !== null && $version === $untilVersion) {
                    break;
                }
            }
        } finally {
            $this->lockStrategy->release($this->lockName);
        }

        return $result;
    }

    /**
     * Ensure schema_versions and ops_nonces exist (idempotent bootstrap).
     *
     * Uses driver detection to emit DDL compatible with both MariaDB/MySQL
     * and SQLite, so the service can run in test environments without a
     * MariaDB connection.
     */
    private function bootstrapTechnicalTables(): void
    {
        $isMySQL = ($this->db->DBDriver === 'MySQLi');

        if ($isMySQL) {
            $schemaVersionsSql = <<<'SQL'
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
        } else {
            // SQLite-compatible DDL: no AUTO_INCREMENT, no ENGINE, no ENUM, no ON UPDATE.
            $schemaVersionsSql = <<<'SQL'
                CREATE TABLE IF NOT EXISTS `schema_versions` (
                    `id`                 INTEGER      PRIMARY KEY AUTOINCREMENT,
                    `version`            VARCHAR(255) NOT NULL UNIQUE,
                    `checksum`           VARCHAR(64)  NOT NULL,
                    `status`             VARCHAR(10)  NOT NULL DEFAULT 'pending',
                    `applied_at`         DATETIME     NULL     DEFAULT NULL,
                    `execution_time_ms`  INTEGER      NULL     DEFAULT NULL,
                    `error_code`         VARCHAR(10)  NULL     DEFAULT NULL,
                    `error_message`      TEXT         NULL     DEFAULT NULL,
                    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL;
        }

        // Cross-database DDL aligned with OpsAuthFilter::bootstrapNonceTable().
        // nonce_hash is the natural key and the PRIMARY KEY used for duplicate-INSERT
        // replay detection. Kept aligned with the greenfield baseline migration
        // (20260611000000_initial_schema.sql) so this idempotent bootstrap, which runs
        // before migrations on a fresh install, never conflicts with it.
        $nonceSql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS ops_nonces (
                nonce_hash   VARCHAR(64)  NOT NULL,
                expires_at   DATETIME     NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (nonce_hash)
            )
            SQL;

        $this->db->query($schemaVersionsSql);
        $this->db->query($nonceSql);

        // Cross-database index check: use CI4's getIndexData() instead of SHOW INDEX.
        $indexes = $this->db->getIndexData('ops_nonces');
        $indexExists = false;
        foreach ($indexes as $index) {
            if ($index->name === 'idx_ops_nonces_expires') {
                $indexExists = true;
                break;
            }
        }
        if (!$indexExists) {
            $this->db->query('CREATE INDEX IF NOT EXISTS idx_ops_nonces_expires ON ops_nonces (expires_at)');
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
     * Maps known drift versions to a schema verification query that must return cnt >= 1
     * before the checksum can be reconciled. Avoids hardcoded version strings in the
     * reconcileChecksum method body — add entries here for future known drifts.
     *
     * @var array<string, array{table: string, column: string}>
     */
    private const DRIFT_SCHEMA_VERIFIERS = [
        '20260614121500_add_last_login_at_to_users' => [
            'table'  => 'users',
            'column' => 'last_login_at',
        ],
    ];

    /**
     * Reconcile a known checksum drift for a specific migration version.
     *
     * Used when a migration file was patched after being successfully applied in
     * production (e.g. a backfill UPDATE was removed). Verifies the actual schema
     * effect of the migration matches the expected outcome before updating the stored
     * checksum to the current file value.
     *
     * @param  string $version           The migration version key (filename without .sql)
     * @param  string $currentChecksum   SHA-256 of the current migration file on disk
     * @return array{ok: bool, action: string, error?: string}
     */
    public function reconcileChecksum(string $version, string $currentChecksum): array
    {
        try {
            return $this->doReconcileChecksum($version, $currentChecksum);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "reconcileChecksum failed for version '{$version}': " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * @return array{ok: bool, action: string, error?: string}
     */
    private function doReconcileChecksum(string $version, string $currentChecksum): array
    {
        // Blank DB: schema_versions not yet created — no drift to reconcile.
        if (!$this->db->tableExists('schema_versions')) {
            return ['ok' => true, 'action' => 'nothing_to_reconcile'];
        }

        $appliedVersions = $this->getAppliedVersions();

        if (!isset($appliedVersions[$version])) {
            // Version not yet applied — no drift to reconcile.
            return ['ok' => true, 'action' => 'nothing_to_reconcile'];
        }

        $existing = $appliedVersions[$version];

        if ($existing['status'] !== 'success') {
            return ['ok' => false, 'action' => 'not_applied', 'error' => 'migration_status_is_not_success'];
        }

        if ($existing['checksum'] === $currentChecksum) {
            return ['ok' => true, 'action' => 'already_reconciled'];
        }

        // TOCTOU guard: re-read the file on disk and verify the checksum the caller observed
        // is still current. Migration files are immutable in production, but this prevents a
        // race where a concurrent deploy replaces the file between the caller's read and this update.
        $filePath = rtrim($this->migrationsPath, '/') . '/' . $version . '.sql';
        if (is_file($filePath)) {
            $onDiskContent = file_get_contents($filePath);
            if ($onDiskContent !== false && hash('sha256', $onDiskContent) !== $currentChecksum) {
                return [
                    'ok'     => false,
                    'action' => 'error',
                    'error'  => 'checksum_changed_during_reconciliation',
                ];
            }
        }

        // For versions with a registered schema verifier, confirm the expected schema effect
        // is present before trusting the checksum reconciliation.
        if (isset(self::DRIFT_SCHEMA_VERIFIERS[$version])) {
            $verifier = self::DRIFT_SCHEMA_VERIFIERS[$version];
            $result   = $this->db->query(
                'SELECT COUNT(*) AS cnt
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?',
                [$verifier['table'], $verifier['column']]
            )->getRowArray();

            if ((int) ($result['cnt'] ?? 0) === 0) {
                return [
                    'ok'     => false,
                    'action' => 'schema_mismatch',
                    'error'  => $verifier['column'] . ' column absent from ' . $verifier['table'] . ' table',
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'UPDATE `schema_versions`
             SET `checksum` = ?, `error_code` = NULL, `error_message` = ?, `updated_at` = ?
             WHERE `version` = ? AND `status` = ?',
            [$currentChecksum, 'Checksum reconciled after file patch', $now, $version, 'success']
        );

        // P4: verify the UPDATE actually matched the row (concurrent status change guard).
        if ($this->db->affectedRows() === 0) {
            return [
                'ok'     => false,
                'action' => 'error',
                'error'  => 'update_affected_no_rows',
            ];
        }

        return ['ok' => true, 'action' => 'reconciled'];
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

}
