-- Migration 5.14 : Préparation architecture stateless des inscriptions
-- Ajoute les colonnes de traçabilité (created_by, accepted_at, rejected_at,
-- canceled_at, canceled_by, viewed_at) à la table signups.
-- La suppression du champ status et la migration des données se feront
-- dans la migration suivante (20260619) une fois tous les champs en place.

ALTER TABLE `signups`
  ADD COLUMN `created_by`   BIGINT UNSIGNED NULL DEFAULT NULL AFTER `admin_notes`,
  ADD COLUMN `viewed_at`    DATETIME        NULL DEFAULT NULL AFTER `created_by`,
  ADD COLUMN `accepted_at`  DATETIME        NULL DEFAULT NULL AFTER `viewed_at`,
  ADD COLUMN `rejected_at`  DATETIME        NULL DEFAULT NULL AFTER `accepted_at`,
  ADD COLUMN `canceled_at`  DATETIME        NULL DEFAULT NULL AFTER `rejected_at`,
  ADD COLUMN `canceled_by`  BIGINT UNSIGNED NULL DEFAULT NULL AFTER `canceled_at`;
