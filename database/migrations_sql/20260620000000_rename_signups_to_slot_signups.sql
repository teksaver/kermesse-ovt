-- Migration 5.13 : Renommer la table signups → slot_signups (ubiquitous language)
-- Indexes and FK constraints on the table are preserved after rename in MariaDB.
RENAME TABLE `signups` TO `slot_signups`;
