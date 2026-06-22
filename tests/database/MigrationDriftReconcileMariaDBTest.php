<?php

declare(strict_types=1);

use App\Services\MigrationRunnerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Story 6.10 — Tests de la remédiation de drift de checksum.
 *
 * Vérifie que MigrationRunnerService::reconcileChecksum() :
 *   - Détecte un drift et met à jour le checksum dans schema_versions.
 *   - Est idempotente si le checksum est déjà réconcilié.
 *   - Refuse de réconcilier une version inconnue ou non-success.
 *   - Vérifie l'effet réel du schéma avant de valider (pour 20260614121500).
 *
 * @group mariadb
 * @internal
 */
final class MigrationDriftReconcileMariaDBTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup  = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $tempMigrationsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('tests');

        if ($db->DBDriver !== 'MySQLi') {
            if (getenv('CI') === 'true') {
                $this->fail('MigrationDriftReconcileMariaDBTest requires MariaDB (database.tests.DBDriver=MySQLi).');
            }
            $this->markTestSkipped('These tests require a MariaDB/MySQL connection.');
        }

        // Partir d'une ardoise vide — supprimer TOUTES les tables pour éviter les FK
        // orphelines laissées par les classes de test précédentes (ex. ManageSlotsMariaDBTest).
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'slot_signups', 'signups', 'slots', 'stands', 'profile_divergences',
            'access_tokens', 'email_events', 'kermesse_user_roles',
            'kermesses', 'users', 'ops_nonces', 'schema_versions',
        ] as $table) {
            $db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $db->query('DROP VIEW IF EXISTS `slot_signups`');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->tempMigrationsDir = sys_get_temp_dir() . '/kermesse_drift_test_' . uniqid('', true);
        mkdir($this->tempMigrationsDir, 0755, true);

        config('Kermesse')->opsMigrationProductionOnly = false;
        config('Kermesse')->opsMigrationLockName       = 'kermesse_drift_test_' . uniqid();
        config('Kermesse')->opsMigrationPath           = $this->tempMigrationsDir;
    }

    protected function tearDown(): void
    {
        try {
            $db = db_connect('tests');
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            // Drop every table the tests may have created — schema_versions and ops_nonces
            // are always present (bootstrapped by MigrationRunnerService), users is created
            // by the drift-test fixtures. FK_CHECKS=0 makes order irrelevant.
            $db->query('DROP TABLE IF EXISTS `schema_versions`');
            $db->query('DROP TABLE IF EXISTS `ops_nonces`');
            $db->query('DROP TABLE IF EXISTS `users`');
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        } finally {
            // Temp directory cleanup must always run, even if DB teardown throws.
            if (is_dir($this->tempMigrationsDir)) {
                $sqlFiles = glob($this->tempMigrationsDir . '/*.sql');
                if ($sqlFiles !== false) {
                    array_map('unlink', $sqlFiles);
                }
                rmdir($this->tempMigrationsDir);
            }

            parent::tearDown();
        }
    }

    private function makeRunner(): MigrationRunnerService
    {
        return new MigrationRunnerService(db_connect('tests'));
    }

    private function writeMigration(string $filename, string $sql): void
    {
        file_put_contents($this->tempMigrationsDir . '/' . $filename, $sql);
    }

    // -----------------------------------------------------------------------
    // Cas nominal : drift détecté et réconcilié
    // -----------------------------------------------------------------------

    public function testReconcileChecksumUpdatesStoredChecksumOnDrift(): void
    {
        // Créer une migration d'initialisation (sans table spéciale) et l'appliquer
        $originalSql = "CREATE TABLE IF NOT EXISTS `users` (`id` INT NOT NULL AUTO_INCREMENT, `last_login_at` DATETIME NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;";
        $this->writeMigration('20260614121500_add_last_login_at_to_users.sql', $originalSql);

        $runner = $this->makeRunner();
        $result = $runner->run();
        $this->assertTrue($result['ok'], 'Initial run must succeed');

        // Modifier le fichier pour simuler un drift (patch post-déploiement)
        $patchedSql = "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL;";
        $this->writeMigration('20260614121500_add_last_login_at_to_users.sql', $patchedSql);
        $patchedChecksum = hash('sha256', $patchedSql);

        // La remédiation doit réussir
        $reconcileResult = $runner->reconcileChecksum('20260614121500_add_last_login_at_to_users', $patchedChecksum);

        $this->assertTrue($reconcileResult['ok'], 'reconcileChecksum must return ok=true');
        $this->assertSame('reconciled', $reconcileResult['action']);

        // Vérifier que le checksum est bien mis à jour en base
        $db  = db_connect('tests');
        $row = $db->query(
            'SELECT `checksum`, `error_code` FROM `schema_versions` WHERE `version` = ?',
            ['20260614121500_add_last_login_at_to_users']
        )->getRowArray();

        $this->assertSame($patchedChecksum, $row['checksum'], 'Stored checksum must match current file');
        $this->assertNull($row['error_code'], 'error_code must be cleared after reconciliation');
    }

    // -----------------------------------------------------------------------
    // Idempotence : réconcilier une version déjà à jour
    // -----------------------------------------------------------------------

    public function testReconcileChecksumIsIdempotentWhenAlreadyReconciled(): void
    {
        $sql      = "SELECT 1;";
        $checksum = hash('sha256', $sql);
        $this->writeMigration('20260614000000_dummy.sql', $sql);

        $runner = $this->makeRunner();
        $runner->run();

        // Première réconciliation
        $r1 = $runner->reconcileChecksum('20260614000000_dummy', $checksum);
        $this->assertTrue($r1['ok']);
        $this->assertSame('already_reconciled', $r1['action']);

        // Deuxième appel identique : idem
        $r2 = $runner->reconcileChecksum('20260614000000_dummy', $checksum);
        $this->assertTrue($r2['ok']);
        $this->assertSame('already_reconciled', $r2['action']);
    }

    // -----------------------------------------------------------------------
    // Version inconnue
    // -----------------------------------------------------------------------

    public function testReconcileChecksumRejectsUnknownVersion(): void
    {
        // Bootstrapper schema_versions sans appliquer de migration
        $this->writeMigration('20260614000000_dummy.sql', 'SELECT 1;');
        $runner = $this->makeRunner();
        $runner->run();

        $result = $runner->reconcileChecksum('20260699999999_unknown_version', 'somechecksum');

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['action']);
    }

    // -----------------------------------------------------------------------
    // Vérification de schéma : la colonne doit exister avant réconciliation
    // -----------------------------------------------------------------------

    public function testReconcileChecksumFor20260614RequiresLastLoginAtColumn(): void
    {
        // Appliquer une migration qui ne crée PAS last_login_at
        $originalSql = "CREATE TABLE IF NOT EXISTS `users` (`id` INT NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB;";
        $this->writeMigration('20260614121500_add_last_login_at_to_users.sql', $originalSql);

        $runner = $this->makeRunner();
        $runner->run();

        // Simuler un "patch" du fichier : la nouvelle version déclare l'ajout de last_login_at,
        // mais la colonne est absente de la base (migration originale ne la créait pas).
        // Le TOCTOU guard exige que le fichier sur disque corresponde au checksum passé.
        $patchedSql = "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL;";
        $this->writeMigration('20260614121500_add_last_login_at_to_users.sql', $patchedSql);

        $result = $runner->reconcileChecksum(
            '20260614121500_add_last_login_at_to_users',
            hash('sha256', $patchedSql)
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('schema_mismatch', $result['action']);
    }
}
