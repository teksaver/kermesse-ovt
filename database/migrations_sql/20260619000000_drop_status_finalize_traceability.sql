-- Migration 5.14 : Finaliser la traçabilité stateless des inscriptions
-- Remplace la colonne physique `status` par des timestamps calculés.
--
-- Règle de calcul du statut (après migration) :
--   deleted_at IS NOT NULL                         → déactivé (stand/créneau supprimé)
--   canceled_at IS NOT NULL AND canceled_by = user_id → cancelled (bénévole)
--   canceled_at IS NOT NULL AND canceled_by != user_id (ou IS NULL) → removed (admin)
--   rejected_at IS NOT NULL                        → refused
--   accepted_at IS NOT NULL                        → certified
--   (created_by IS NULL OR created_by != user_id) AND accepted_at IS NULL → unconfirmed
--   sinon                                          → active

-- 1. Backfill canceled_at / canceled_by pour les annulations bénévole
UPDATE `signups`
SET
    `canceled_at` = COALESCE(`canceled_at`, `updated_at`),
    `canceled_by` = COALESCE(`canceled_by`, `user_id`)
WHERE `status` = 'cancelled' AND `deleted_at` IS NULL;

-- 2. Backfill canceled_at pour les suppressions admin (canceled_by intentionnellement NULL
--    car l'ID de l'admin n'était pas enregistré avant la Story 5.14)
UPDATE `signups`
SET `canceled_at` = COALESCE(`canceled_at`, `updated_at`)
WHERE `status` = 'removed' AND `deleted_at` IS NULL AND `canceled_at` IS NULL;

-- 3. Backfill rejected_at pour les refus
UPDATE `signups`
SET `rejected_at` = COALESCE(`rejected_at`, `updated_at`)
WHERE `status` = 'refused' AND `deleted_at` IS NULL AND `rejected_at` IS NULL;

-- 4. Soft-delete les inscriptions désactivées par suppression de stand/créneau
UPDATE `signups`
SET `deleted_at` = COALESCE(`deleted_at`, `updated_at`)
WHERE `status` IN ('deactivated', 'deleted') AND `deleted_at` IS NULL;

-- 5. Supprimer la colonne physique status
ALTER TABLE `signups` DROP COLUMN `status`;
