-- Migration: remplace la contrainte UNIQUE (user_id, slot_id) sur signups par un
-- index ordinaire, pour permettre à un bénévole de se réinscrire sur un créneau
-- qu'il a précédemment annulé.
--
-- La contrainte uq_signups_user_slot bloquait tout second INSERT pour la même paire
-- (user_id, slot_id) même si la ligne existante avait status='cancelled'. Le duplicate
-- check applicatif (SignupModel::findActiveByUserAndSlot) filtre déjà les statuts
-- inactifs via un locking read FOR UPDATE — il est l'unique garde-fou contre les
-- doublons actifs et il est transactionnellement correct. L'index de remplacement
-- préserve les performances des requêtes de vérification.

ALTER TABLE `signups` DROP INDEX `uq_signups_user_slot`;
ALTER TABLE `signups` ADD INDEX `idx_signups_user_slot` (`user_id`, `slot_id`);
