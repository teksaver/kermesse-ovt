-- Migration: ajoute last_modified_by_user_id et last_modified_at sur signups
-- pour tracer les modifications admin (Stories 5.3, 5.10, 5.11, 5.12).
--
-- last_modified_by_user_id : identifiant de l'admin ayant effectué la dernière
--   modification. NULL pour toute inscription jamais modifiée par un admin.
--   Référence users.id avec RESTRICT : un user référencé ici ne peut pas être
--   supprimé physiquement (cohérent avec fk_kermesses_creator). En pratique,
--   le retrait d'un rôle admin ne touche que kermesse_user_roles — la ligne
--   users persiste et la traçabilité est préservée.
-- last_modified_at : horodatage de la dernière modification admin. NULL si jamais
--   modifiée par un admin.

ALTER TABLE `signups`
    ADD COLUMN `last_modified_by_user_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Admin ayant effectué la dernière modification (NULL = jamais modifié)',
    ADD COLUMN `last_modified_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Horodatage de la dernière modification admin (NULL = jamais modifié)',
    ADD CONSTRAINT `fk_signups_modifier`
        FOREIGN KEY (`last_modified_by_user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;
