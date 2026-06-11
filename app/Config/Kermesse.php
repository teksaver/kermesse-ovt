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
    // Ops runtime probe
    // -----------------------------------------------------------------

    /**
     * Enables the temporary runtime probe at POST /ops/probe.
     * Disabled by default — only switched on locally (Docker) to measure the
     * target runtime. Never enable on a long-lived public environment.
     */
    public bool $opsProbeEnabled = false;

    // -----------------------------------------------------------------
    // Ops release activation
    // -----------------------------------------------------------------

    /**
     * Named lock used by GET_LOCK() to serialise activation runs.
     * Distinct from the migration lock to avoid blocking one with the other.
     */
    public string $opsActivateLockName = 'kermesse_ops_activate_lock';

    /** Number of latest releases to retain after a successful activation. Older ones are pruned. */
    public int $releasesRetention = 3;

    /**
     * Base path for the deployment layout: releases/, staging/, current, CURRENT_RELEASE.
     * Defaults to the parent of ROOTPATH (i.e. the kermesse/ home directory on Ouvaton).
     * Override in .env for local rehearsal or tests.
     */
    public string $opsActivateBasePath = '';

    // -----------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------

    /** Application-level secret for token generation. */
    public string $tokenSecret = '';

    public int $ownerValidationTokenTTL = 86400;
    public int $ownerLoginTokenTTL = 900;
    public int $volunteerManagementTokenTTL = 1209600;

    /** TTL in seconds for the universal Magic Link token. Default: 15 minutes. */
    public int $magicLinkTokenTTL = 900;
}
