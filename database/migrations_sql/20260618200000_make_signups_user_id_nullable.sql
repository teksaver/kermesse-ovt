-- Migration 5.14 : Rendre signups.user_id nullable pour les inscriptions visiteurs
--
-- Avant 5.14, chaque inscription était rattachée à un utilisateur (user_id NOT NULL).
-- Story 5.14 introduit les inscriptions orphelines (visiteur non connecté, email inconnu) :
-- ces lignes ont user_id IS NULL jusqu'au rattachement lors de la première connexion
-- via resolveOrphanSignups().
--
-- La FK est remplacée par ON DELETE SET NULL pour que la suppression d'un utilisateur
-- préserve les inscriptions au lieu de les bloquer.
--
-- Note: les 3 ALTER TABLE doivent être séparés — MariaDB refuse de DROP et re-ADD
-- une contrainte avec le même nom dans un seul statement (errno 121).

ALTER TABLE `signups` DROP FOREIGN KEY `fk_signups_user`;

ALTER TABLE `signups` MODIFY COLUMN `user_id` BIGINT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `signups`
    ADD CONSTRAINT `fk_signups_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
