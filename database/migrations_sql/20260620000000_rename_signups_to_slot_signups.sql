-- Story 6.10 : Contraction — renommage physique signups → slot_signups.
-- La vue de compatibilité créée par 20260619500000 est supprimée en premier
-- pour éviter un conflit de nom lors du RENAME TABLE.
-- Les index et clés étrangères (stand_id → stands, slot_id → slots, user_id → users)
-- sont automatiquement préservés par MariaDB lors du renommage.
--
-- POINT DE NON-RETOUR : après cette migration, le code applicatif doit référencer
-- slot_signups (pas signups). L'ancien code (pré-6.10) est incompatible après ce point.
--
-- Procédure de rollback (manuel, en cas de régression post-migration) :
--   1. Remettre l'ancien code en production (rollback du déploiement).
--   2. Exécuter sur la base de données :
--        RENAME TABLE `slot_signups` TO `signups`;
--        CREATE OR REPLACE VIEW `slot_signups` AS SELECT * FROM `signups`;
--      → le nouveau code (qui tourne encore brièvement) reste compatible via la vue.
--   3. Dès que l'ancien code est seul en production, supprimer la vue :
--        DROP VIEW IF EXISTS `slot_signups`;
DROP VIEW IF EXISTS `slot_signups`;
RENAME TABLE `signups` TO `slot_signups`;
