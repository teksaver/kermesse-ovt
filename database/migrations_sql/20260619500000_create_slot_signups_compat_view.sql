-- Couche de compatibilité expand/contract (Story 6.10).
-- Crée la vue `slot_signups` sur `signups` pour que le code utilisant le nom
-- canonique (SlotSignupModel) fonctionne avant le renommage physique.
-- Une vue MariaDB de sélection simple est updatable (INSERT/UPDATE/DELETE autorisés).
-- La vue est supprimée par la migration 20260620000000 avant le RENAME TABLE.
CREATE OR REPLACE VIEW `slot_signups` AS SELECT * FROM `signups`;
