<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Kermesse application configuration.
 *
 * Values are overridden by matching `.env` entries
 * (e.g. `kermesse.opsMigrationHmacSecret`).
 */
class Kermesse extends BaseConfig
{
    /** Canonical public URL used in emails and absolute links. */
    public string $publicBaseURL = 'https://example.invalid/';

    // -----------------------------------------------------------------
    // Ops migration runner
    // -----------------------------------------------------------------

    /** HMAC-SHA256 shared secret for ops endpoint authentication. */
    public string $opsMigrationHmacSecret = '';

    /** Maximum allowed clock skew in seconds for timestamp validation. */
    public int $opsMigrationAllowedTimestampSkew = 300;

    /** Nonce TTL in seconds — nonces older than this are considered expired. */
    public int $opsMigrationNonceTTL = 600;

    /** When true, ops/migrate is only accepted in production environment. */
    public bool $opsMigrationProductionOnly = true;

    /** Named lock used by GET_LOCK() to serialise migration runs. */
    public string $opsMigrationLockName = 'kermesse_ops_migration_lock';

    /** Optional override for SQL migration files path, mainly for tests. */
    public string $opsMigrationPath = '';

    // -----------------------------------------------------------------
    // Ops probe
    // -----------------------------------------------------------------

    /** Enable POST /ops/probe. Disabled by default; enable locally via docker-compose or .env. */
    public bool $opsProbeEnabled = false;

    // -----------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------

    /** Application-level secret for token generation. */
    public string $tokenSecret = '';

    public int $ownerValidationTokenTTL = 86400;
    public int $ownerLoginTokenTTL = 900;
    public int $volunteerManagementTokenTTL = 1209600;
}
