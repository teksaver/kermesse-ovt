-- Migration: 20260611000000_initial_schema
-- Description: Unified greenfield baseline schema for Kermesse (Story 1.1).
-- This is the genesis migration applied by MigrationRunnerService on a fresh DB;
-- subsequent EPIC changes are added as incremental migrations_sql/*.sql on top.
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_general_ci

-- -----------------------------------------------------
-- Table: schema_versions
-- Bootstrap table: runner self-initialises on blank DB.
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
-- Anti-replay tokens for ops endpoints (10-min TTL).
-- No AUTO_INCREMENT id: nonce_hash is the natural PK.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `ops_nonces` (
    `nonce_hash`   VARCHAR(64)  NOT NULL,
    `expires_at`   DATETIME     NOT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`nonce_hash`),
    INDEX `idx_ops_nonces_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: users
-- Global identity: one record per email address.
-- Replaces the pre-pivot separate owners + volunteers tables.
-- No passwords: all access via Magic Link.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(320)    NOT NULL COMMENT 'Normalised (lowercase + trim)',
    `email_hash` VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of normalised email',
    `first_name` VARCHAR(100)    NOT NULL DEFAULT '',
    `last_name`  VARCHAR(100)    NOT NULL DEFAULT '',
    `phone`      VARCHAR(30)     NOT NULL DEFAULT '',
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email_hash` (`email_hash`),
    KEY `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: kermesses
-- Event created by a user (creator gets Owner role in kermesse_user_roles).
-- created_by references users.id.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `kermesses` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_by`        BIGINT UNSIGNED NOT NULL COMMENT 'User who created this kermesse (Owner)',
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
    KEY `idx_kermesses_creator` (`created_by`),
    KEY `idx_kermesses_status` (`status`),
    CONSTRAINT `fk_kermesses_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: kermesse_user_roles
-- Per-kermesse RBAC: Owner, Admin, Gestionnaire, Bénévole.
-- One row per (kermesse_id, user_id) pair.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `kermesse_user_roles` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kermesse_id` BIGINT UNSIGNED NOT NULL,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `role`        ENUM('owner','admin','gestionnaire','benevole') NOT NULL,
    `invited_by`  BIGINT UNSIGNED NULL     DEFAULT NULL COMMENT 'User who granted this role (NULL for creator)',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_role_per_kermesse` (`kermesse_id`, `user_id`),
    KEY `idx_roles_user` (`user_id`),
    CONSTRAINT `fk_roles_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_roles_inviter` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: access_tokens
-- Scoped, hashed, single-use Magic Link and invitation tokens.
-- token_type: magic_link (universal login) | role_invitation | signup_management
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `access_tokens` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash`  VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of raw token',
    `token_type`  ENUM('magic_link','role_invitation','signup_management') NOT NULL,
    `user_id`     BIGINT UNSIGNED NULL     DEFAULT NULL,
    `kermesse_id` BIGINT UNSIGNED NULL     DEFAULT NULL,
    `email`       VARCHAR(320)    NULL     DEFAULT NULL COMMENT 'Target email for delivery',
    `expires_at`  DATETIME        NOT NULL,
    `used_at`     DATETIME        NULL     DEFAULT NULL,
    `revoked_at`  DATETIME        NULL     DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_access_tokens_hash` (`token_hash`),
    KEY `idx_access_tokens_type` (`token_type`),
    KEY `idx_access_tokens_user` (`user_id`),
    KEY `idx_access_tokens_kermesse` (`kermesse_id`),
    KEY `idx_access_tokens_expires` (`expires_at`),
    CONSTRAINT `fk_access_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_tokens_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: email_events
-- Records every email sending attempt and outcome.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_events` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`       VARCHAR(50)     NOT NULL,
    `status`           ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    `recipient_email`  VARCHAR(320)    NOT NULL,
    `recipient_hash`   VARCHAR(64)     NOT NULL COMMENT 'SHA-256 hex of normalised recipient email',
    `error_message`    TEXT            NULL     DEFAULT NULL,
    `metadata`         JSON            NULL     DEFAULT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_events_type` (`event_type`),
    KEY `idx_email_events_status` (`status`),
    KEY `idx_email_events_recipient` (`recipient_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: profile_divergences
-- Tracks when a public signup submits different first_name/last_name/phone
-- than the user's stored profile. Resolved via Story 3.6 (profile resolution).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `profile_divergences` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`              BIGINT UNSIGNED NOT NULL,
    `kermesse_id`          BIGINT UNSIGNED NOT NULL,
    `signup_id`            BIGINT UNSIGNED NULL     DEFAULT NULL,
    `submitted_first_name` VARCHAR(100)    NOT NULL,
    `submitted_last_name`  VARCHAR(100)    NOT NULL,
    `submitted_phone`      VARCHAR(30)     NOT NULL DEFAULT '',
    `resolved_at`          DATETIME        NULL     DEFAULT NULL,
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_divergences_user` (`user_id`),
    KEY `idx_divergences_unresolved` (`user_id`, `resolved_at`),
    CONSTRAINT `fk_divergences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_divergences_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: stands
-- Stands belong to a kermesse and active names are unique
-- per kermesse using a generated normalized key.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `stands` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kermesse_id`     BIGINT UNSIGNED NOT NULL,
    `name`            VARCHAR(255)    NOT NULL,
    `display_order`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`          ENUM('active','deactivated') NOT NULL DEFAULT 'active',
    `active_name_key` VARCHAR(255) GENERATED ALWAYS AS (
        CASE WHEN `status` = 'active' THEN LOWER(TRIM(`name`)) ELSE NULL END
    ) STORED,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stands_active_name` (`kermesse_id`, `active_name_key`),
    KEY `idx_stands_kermesse` (`kermesse_id`),
    KEY `idx_stands_kermesse_order` (`kermesse_id`, `display_order`),
    CONSTRAINT `fk_stands_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: slots
-- Time slots for a stand, with capacity.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `slots` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `stand_id`    BIGINT UNSIGNED NOT NULL,
    `starts_at`   DATETIME        NOT NULL,
    `ends_at`     DATETIME        NOT NULL,
    `capacity`    INT UNSIGNED    NOT NULL,
    `status`      ENUM('active','deactivated') NOT NULL DEFAULT 'active',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slots_stand` (`stand_id`),
    KEY `idx_slots_stand_order` (`stand_id`, `starts_at`, `id`),
    CONSTRAINT `fk_slots_stand` FOREIGN KEY (`stand_id`) REFERENCES `stands` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: signups
-- One row per user-slot inscription.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `signups` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_id`    BIGINT UNSIGNED NOT NULL,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `status`     ENUM('active','cancelled','deactivated','deleted') NOT NULL DEFAULT 'active',
    `deleted_at` DATETIME        NULL     DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_signups_user_slot` (`user_id`, `slot_id`),
    KEY `idx_signups_slot` (`slot_id`),
    KEY `idx_signups_user` (`user_id`),
    KEY `idx_signups_status` (`status`),
    CONSTRAINT `fk_signups_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_signups_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
