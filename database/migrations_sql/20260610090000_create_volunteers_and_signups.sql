-- Migration: 20260610090000_create_volunteers_and_signups
-- Description: Create volunteers and signups tables for the public inscription flow (Story 3.3).
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_general_ci

-- -----------------------------------------------------
-- Table: volunteers
-- One profile per (kermesse, email) pair. Email is stored
-- normalized (lowercased, trimmed) to ensure casing variants
-- map to the same identity (AC3). No passwords — passwordless
-- access via volunteer_management tokens only.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `volunteers` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kermesse_id`  BIGINT UNSIGNED NOT NULL,
    `first_name`   VARCHAR(100)    NOT NULL,
    `last_name`    VARCHAR(100)    NOT NULL,
    `email`        VARCHAR(254)    NOT NULL COMMENT 'Normalized: lowercase + trim',
    `phone`        VARCHAR(30)     NOT NULL DEFAULT '',
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_volunteers_kermesse_email` (`kermesse_id`, `email`),
    KEY `idx_volunteers_kermesse` (`kermesse_id`),
    CONSTRAINT `fk_volunteers_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------
-- Table: signups
-- One row per volunteer-slot inscription. Uses volunteer_id
-- FK instead of denormalized volunteer_name. Status tracks
-- lifecycle; deleted_at supports soft deletes.
-- Active = status NOT IN ('cancelled','deactivated','deleted') AND deleted_at IS NULL.
-- -----------------------------------------------------
DROP TABLE IF EXISTS `signups`;
CREATE TABLE IF NOT EXISTS `signups` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_id`      BIGINT UNSIGNED NOT NULL,
    `volunteer_id` BIGINT UNSIGNED NOT NULL,
    `status`       ENUM('active','cancelled','deactivated','deleted') NOT NULL DEFAULT 'active',
    `deleted_at`   DATETIME        NULL     DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_signups_slot` (`slot_id`),
    KEY `idx_signups_volunteer` (`volunteer_id`),
    KEY `idx_signups_status` (`status`),
    CONSTRAINT `fk_signups_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_signups_volunteer` FOREIGN KEY (`volunteer_id`) REFERENCES `volunteers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
