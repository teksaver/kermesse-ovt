-- Migration: 20260606090000_create_slots
-- Description: Create slots table for kermesse stand time slots.
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_general_ci

-- -----------------------------------------------------
-- Table: slots
-- Time slots for a stand. Capacity and status control
-- volunteer availability. No hard deletes; use status = 'deactivated'.
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
