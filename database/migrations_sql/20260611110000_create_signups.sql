-- Migration: 20260611110000_create_signups
-- Description: Create signups table referencing users (unified identity model).
--              Replaces the pre-pivot volunteers + signups migration.
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_general_ci

-- -----------------------------------------------------
-- Table: signups
-- One row per user-slot inscription.
-- Status lifecycle: active → cancelled | deactivated | deleted.
-- Active = status NOT IN ('cancelled','deactivated','deleted') AND deleted_at IS NULL.
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
    KEY `idx_signups_slot` (`slot_id`),
    KEY `idx_signups_user` (`user_id`),
    KEY `idx_signups_status` (`status`),
    CONSTRAINT `fk_signups_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_signups_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
