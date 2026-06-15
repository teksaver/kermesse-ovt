-- Migration: ajoute first_access_at et last_access_at sur kermesse_user_roles
-- pour tracer le premier accès au tableau de bord (par kermesse) et l'activité récente.
--
-- first_access_at : positionné par RoleService::recordAccess() lors du premier
--   chargement du dashboard pour cette kermesse (Story 5.2).
--   NULL tant que l'utilisateur n'a jamais ouvert le tableau de bord.
--   Sert d'indicateur "invitation non encore acceptée" (Story 5.5) et de verrou
--   d'édition admin (Story 5.10).
-- last_access_at  : mis à jour à chaque chargement du dashboard par kermesse.
--   Aucune interface utilisateur prévue pour l'instant ; suivi d'activité.

ALTER TABLE `kermesse_user_roles`
    ADD COLUMN `first_access_at` DATETIME NULL DEFAULT NULL
        COMMENT '1er accès au dashboard pour cette kermesse ; NULL = jamais accédé'
        AFTER `accepted_at`,
    ADD COLUMN `last_access_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Dernier accès au dashboard ; mis à jour à chaque visite'
        AFTER `first_access_at`;
