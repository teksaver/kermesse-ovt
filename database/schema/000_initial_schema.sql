-- Migration: 20260602161500_initial_schema
-- Description: Create initial Kermesse schema with core tables.
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_general_ci

-- -----------------------------------------------------
-- Table: schema_versions
-- Tracks applied SQL migrations (version, checksum, status).
-- Bootstrap table: uses IF NOT EXISTS so the runner can
-- self-initialise on a blank database.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_versions` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version`            VARCHAR(255)    NOT NULL,
    `checksum`           VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the migration file content',
    `status`             ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    `applied_at`         DATETIME        NULL     DEFAULT NULL,
    `execution_time_ms`  INT UNSIGNED    NULL     DEFAULT NULL,
    `error_code`         VARCHAR(10)     NULL     DEFAULT NULL,
    `error_message`      TEXT            NULL     DEFAULT NULL,
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_versions_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: ops_nonces
-- Stores hashed nonces for anti-replay protection on
-- ops endpoints. Bootstrap table: uses IF NOT EXISTS.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ops_nonces` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nonce_hash`   VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the raw nonce',
    `expires_at`   DATETIME        NOT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ops_nonces_hash` (`nonce_hash`),
    KEY `idx_ops_nonces_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: owners
-- Owner identity (MVP): email-based, explicit status.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `owners` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`                 VARCHAR(320)    NOT NULL COMMENT 'Normalised email address',
    `email_hash`            VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of lower-cased trimmed email',
    `display_name`          VARCHAR(255)    NOT NULL DEFAULT '',
    `status`                ENUM('owner_pending','active') NOT NULL DEFAULT 'owner_pending',
    `email_verified_at`     DATETIME        NULL     DEFAULT NULL,
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_owners_email_hash` (`email_hash`),
    KEY `idx_owners_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: kermesses
-- Event created by an owner.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `kermesses` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_id`          BIGINT UNSIGNED NOT NULL,
    `public_slug`       VARCHAR(255)    NOT NULL,
    `name`              VARCHAR(255)    NOT NULL,
    `event_date`        DATE            NULL     DEFAULT NULL,
    `location`          VARCHAR(255)    NOT NULL DEFAULT '',
    `short_description` VARCHAR(500)    NOT NULL DEFAULT '',
    `timezone`          VARCHAR(64)     NOT NULL DEFAULT 'Europe/Paris',
    `status`            ENUM('preparation','open','closed') NOT NULL DEFAULT 'preparation',
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_kermesses_slug` (`public_slug`),
    KEY `idx_kermesses_owner` (`owner_id`),
    KEY `idx_kermesses_status` (`status`),
    CONSTRAINT `fk_kermesses_owner` FOREIGN KEY (`owner_id`) REFERENCES `owners` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: access_tokens
-- Hashed tokens only — never store raw tokens.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `access_tokens` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash`     VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of the raw token',
    `token_type`     ENUM('owner_validation','owner_login','volunteer_management') NOT NULL,
    `owner_id`       BIGINT UNSIGNED NULL     DEFAULT NULL,
    `kermesse_id`    BIGINT UNSIGNED NULL     DEFAULT NULL,
    `email`          VARCHAR(320)    NULL     DEFAULT NULL COMMENT 'Target email for token delivery',
    `expires_at`     DATETIME        NOT NULL,
    `used_at`        DATETIME        NULL     DEFAULT NULL,
    `revoked_at`     DATETIME        NULL     DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_access_tokens_hash` (`token_hash`),
    KEY `idx_access_tokens_type` (`token_type`),
    KEY `idx_access_tokens_owner` (`owner_id`),
    KEY `idx_access_tokens_kermesse` (`kermesse_id`),
    KEY `idx_access_tokens_expires` (`expires_at`),
    CONSTRAINT `fk_access_tokens_owner` FOREIGN KEY (`owner_id`) REFERENCES `owners` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_tokens_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: email_events
-- Tracks email sending attempts and outcomes.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_events` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`        VARCHAR(50)     NOT NULL COMMENT 'E.g. owner_validation, owner_login',
    `status`            ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    `recipient_email`   VARCHAR(320)    NOT NULL,
    `recipient_hash`    VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of lower-cased trimmed recipient email',
    `error_message`     TEXT            NULL     DEFAULT NULL,
    `metadata`          JSON            NULL     DEFAULT NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_events_type` (`event_type`),
    KEY `idx_email_events_status` (`status`),
    KEY `idx_email_events_recipient` (`recipient_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
