-- Migration 5.14 : Rendre signups.user_id nullable pour les inscriptions visiteurs
--
-- Avant 5.14, chaque inscription était rattachée à un utilisateur (user_id NOT NULL).
-- Story 5.14 introduit les inscriptions orphelines (visiteur non connecté, email inconnu) :
-- ces lignes ont user_id IS NULL jusqu'au rattachement lors de la première connexion
-- via resolveOrphanSignups().
--
-- On supprime la contrainte FK fk_signups_user (DELETE RESTRICT incompatible avec NULL)
-- et on la remplace par une FK nullable (ON DELETE SET NULL) pour que la suppression
-- d'un utilisateur n'invalide pas les inscriptions orphelines qui lui ont été rattachées.

ALTER TABLE `signups`
    DROP FOREIGN KEY `fk_signups_user`,
    MODIFY COLUMN `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT `fk_signups_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
