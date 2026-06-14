-- Migration: ajoute invited_at et accepted_at sur kermesse_user_roles pour tracker
-- l'état d'invitation et d'acceptation par kermesse (et non plus via users.last_login_at
-- qui est un champ global révélant la présence d'un utilisateur sur la plateforme).
--
-- invited_at : positionné par RoleService::assignRole() à chaque invitation.
--              NULL pour les bénévoles (rôle acquis par inscription, non par invitation).
-- accepted_at : positionné par MagicLinkController::verify() lors du premier accès
--               à la kermesse via magic link. Idempotent (non ré-écrit si déjà NULL).
--
-- Backfill one-shot pour les lignes existantes (invited_by IS NOT NULL) :
--   - invited_at  ← kermesse_user_roles.created_at  (date d'assignation du rôle)
--   - accepted_at ← users.last_login_at              (NULL si jamais connecté)

ALTER TABLE `kermesse_user_roles`
    ADD COLUMN `invited_at`  DATETIME NULL DEFAULT NULL AFTER `invited_by`,
    ADD COLUMN `accepted_at` DATETIME NULL DEFAULT NULL AFTER `invited_at`;

-- Backfill invited_at
UPDATE `kermesse_user_roles`
SET    `invited_at` = `created_at`
WHERE  `invited_by` IS NOT NULL
  AND  `invited_at` IS NULL;

-- Backfill accepted_at depuis last_login_at global de l'utilisateur
UPDATE `kermesse_user_roles` kur
JOIN   `users` u ON u.`id` = kur.`user_id`
SET    kur.`accepted_at` = u.`last_login_at`
WHERE  kur.`invited_by`   IS NOT NULL
  AND  u.`last_login_at`  IS NOT NULL
  AND  kur.`accepted_at`  IS NULL;
