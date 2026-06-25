-- Migration : Architecture Stateless (Snapshot) et Ajout des Notes Admin
-- Ce script effectue la migration de données avant de supprimer la table profile_divergences.

-- 1. Ajout de la colonne admin_notes pour les mémos d'organisation (non visibles publiquement)
ALTER TABLE `signups`
ADD COLUMN `admin_notes` TEXT NULL DEFAULT NULL;

-- 2. Migration One-Shot : on préserve l'historique des divergences en les copiant dans les "snapshots" signups
UPDATE `signups` s
INNER JOIN `profile_divergences` pd ON pd.signup_id = s.id
SET s.first_name = pd.submitted_first_name,
    s.last_name = pd.submitted_last_name,
    s.phone = pd.submitted_phone;

-- 3. Backfill des utilisateurs "fantômes" (créés par invitation sans nom/prénom)
-- On remplit leur profil global avec les informations issues de leurs inscriptions
UPDATE `users` u
INNER JOIN `signups` s ON s.user_id = u.id
SET u.first_name = s.first_name,
    u.last_name = s.last_name,
    u.phone = IF(u.phone = '', s.phone, u.phone)
WHERE (u.first_name = '' OR u.last_name = '') 
  AND s.first_name != '';

-- 4. Épuration de la base de données : suppression de la table des divergences
DROP TABLE IF EXISTS `profile_divergences`;
