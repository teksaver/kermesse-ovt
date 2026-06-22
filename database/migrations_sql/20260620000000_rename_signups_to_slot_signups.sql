-- Story 6.10 : Contraction — renommage physique signups → slot_signups.
-- La vue de compatibilité créée par 20260619500000 est supprimée en premier
-- pour éviter un conflit de nom lors du RENAME TABLE.
-- Les index et clés étrangères (stand_id → stands, slot_id → slots, user_id → users)
-- sont automatiquement préservés par MariaDB lors du renommage.
DROP VIEW IF EXISTS `slot_signups`;
RENAME TABLE `signups` TO `slot_signups`;
