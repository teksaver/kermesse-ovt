-- Migration: 20260605180000_create_stands
-- Description: Add stands table for kermesse stand management (Story 2.2).
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_general_ci

-- -----------------------------------------------------
-- Table: stands
-- Stands belong to a kermesse. status=active for visible
-- stands; deactivated reserved for Story 2.3 (deletion).
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `stands` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kermesse_id`   BIGINT UNSIGNED NOT NULL,
    `name`          VARCHAR(255)    NOT NULL,
    `display_order` INT UNSIGNED    NOT NULL DEFAULT 0,
    `status`        ENUM('active','deactivated') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stands_kermesse` (`kermesse_id`),
    KEY `idx_stands_kermesse_order` (`kermesse_id`, `display_order`),
    CONSTRAINT `fk_stands_kermesse` FOREIGN KEY (`kermesse_id`) REFERENCES `kermesses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
