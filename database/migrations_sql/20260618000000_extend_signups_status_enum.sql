-- Migration 5.10 : Extension de l'ENUM status de la table signups
-- Ajoute les nouvelles valeurs du cycle de vie complet :
--   active statuses  : unconfirmed, confirmed, certified, seen
--   historical statuses : removed (annulation admin), refused
-- Les valeurs historiques 'cancelled', 'deactivated', 'deleted' sont conservées.
ALTER TABLE `signups`
  MODIFY `status` ENUM(
    'active',
    'cancelled',
    'deactivated',
    'deleted',
    'unconfirmed',
    'confirmed',
    'certified',
    'seen',
    'refused',
    'removed'
  ) NOT NULL DEFAULT 'active';
