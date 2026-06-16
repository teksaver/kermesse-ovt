-- Migration: ajoute les colonnes de contact (copie locale) sur signups
-- pour Story 5.10 — annuler et corriger une inscription.
--
-- La fiche d'inscription porte sa propre copie prénom/nom/email/téléphone,
-- distincte du profil global (users). Ces colonnes sont NULL pour toute
-- inscription jamais modifiée par un admin via la correction de fiche.
--
-- Règle fondamentale : un admin ne peut écrire que dans ces colonnes ; il ne
-- touche JAMAIS la table users (Story 5.10 AC2). La valeur affichée dans
-- l'interface admin provient : si non-NULL → colonnes signups (copie admin) ;
-- si NULL ET first_access_at IS NULL → colonnes users (profil global, non encore
-- validé par le bénévole pour cette kermesse) ; si NULL ET first_access_at IS NOT
-- NULL → colonnes users (profil validé, lecture seule pour l'admin).

ALTER TABLE `signups`
    ADD COLUMN IF NOT EXISTS `first_name` VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Copie admin du prénom (NULL = jamais corrigé)',
    ADD COLUMN IF NOT EXISTS `last_name`  VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Copie admin du nom de famille (NULL = jamais corrigé)',
    ADD COLUMN IF NOT EXISTS `email`      VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Copie admin de l''email (NULL = jamais corrigé)',
    ADD COLUMN IF NOT EXISTS `phone`      VARCHAR(30)  NULL DEFAULT NULL
        COMMENT 'Copie admin du téléphone (NULL = jamais corrigé)';
