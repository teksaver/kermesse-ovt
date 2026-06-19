-- E2E Fixtures — KERMESSE TEST ONLY
-- Idempotent: drops then recreates E2E test data.
-- Tables use no DB prefix (docker-compose sets DBPrefix = "").
--
-- Raw magic-link tokens (single-use, consumed by global-setup.ts):
--   owner    -> e2e-magic-owner-01    hash: 430342030be549ef6787d76a2f43874484ef3bd2f357cbcc73164ad229f0b8b0
--   admin    -> e2e-magic-admin-01    hash: 6312eaf920e18383e1ea309855c9ddf48ec74a005baa78e4000c5abd34877fd4
--   benevole -> e2e-magic-benevole-01 hash: 27f2c891ac51a0a843910713b155b34827fb06e74b705669ac943b0aed0a8834

SET @slug = 'kermesse-e2e-test';

-- ----------------------------------------------------------------
-- Cleanup (FK-safe order: most-dependent first)
-- ----------------------------------------------------------------
DELETE s FROM `signups` s
  INNER JOIN `slots` sl  ON sl.id  = s.slot_id
  INNER JOIN `stands` st ON st.id  = sl.stand_id
  INNER JOIN `kermesses` k ON k.id = st.kermesse_id
  WHERE k.public_slug = @slug;

DELETE FROM `access_tokens` WHERE `email` LIKE '%@e2e.test';

DELETE kur FROM `kermesse_user_roles` kur
  INNER JOIN `kermesses` k ON k.id = kur.kermesse_id
  WHERE k.public_slug = @slug;

DELETE sl FROM `slots` sl
  INNER JOIN `stands` st ON st.id  = sl.stand_id
  INNER JOIN `kermesses` k ON k.id = st.kermesse_id
  WHERE k.public_slug = @slug;

DELETE st FROM `stands` st
  INNER JOIN `kermesses` k ON k.id = st.kermesse_id
  WHERE k.public_slug = @slug;

-- Legacy volunteers table (pre-greenfield) — must be cleaned before kermesses
DELETE v FROM `volunteers` v
  INNER JOIN `kermesses` k ON k.id = v.kermesse_id
  WHERE k.public_slug = @slug;

DELETE FROM `kermesses` WHERE `public_slug` = @slug;
DELETE FROM `users` WHERE `email` LIKE '%@e2e.test';

-- ----------------------------------------------------------------
-- Users
-- ----------------------------------------------------------------
INSERT INTO `users` (`email`, `email_hash`, `first_name`, `last_name`, `phone`) VALUES
  ('owner@e2e.test',      SHA2('owner@e2e.test',      256), 'Alice',  'Owner',  ''),
  ('admin@e2e.test',      SHA2('admin@e2e.test',       256), 'Bob',    'Admin',  ''),
  ('gestionnaire@e2e.test', SHA2('gestionnaire@e2e.test', 256), 'Carl', 'Gestion', ''),
  ('benevole@e2e.test',   SHA2('benevole@e2e.test',    256), 'Dana',   'Benev',  '');

SET @owner_id     = (SELECT `id` FROM `users` WHERE `email` = 'owner@e2e.test');
SET @admin_id     = (SELECT `id` FROM `users` WHERE `email` = 'admin@e2e.test');
SET @gestion_id   = (SELECT `id` FROM `users` WHERE `email` = 'gestionnaire@e2e.test');
SET @benevole_id  = (SELECT `id` FROM `users` WHERE `email` = 'benevole@e2e.test');

-- ----------------------------------------------------------------
-- Kermesse (open so slots can be added)
-- ----------------------------------------------------------------
INSERT INTO `kermesses`
  (`created_by`, `public_slug`, `name`, `event_date`, `location`, `short_description`, `status`)
VALUES
  (@owner_id, @slug, 'Kermesse E2E', '2026-10-15', 'Salle de test', 'Kermesse de test automatisé', 'open');

SET @kermesse_id = LAST_INSERT_ID();

-- ----------------------------------------------------------------
-- Roles
-- first_access_at set now so admin/gestionnaire appear as active members
-- (not pending) even before their first real dashboard visit.
-- ----------------------------------------------------------------
INSERT INTO `kermesse_user_roles`
  (`kermesse_id`, `user_id`, `role`, `invited_by`, `accepted_at`, `first_access_at`)
VALUES
  (@kermesse_id, @owner_id,    'owner',        NULL,       NOW(), NOW()),
  (@kermesse_id, @admin_id,    'admin',        @owner_id,  NOW(), NOW()),
  (@kermesse_id, @gestion_id,  'gestionnaire', @owner_id,  NOW(), NOW()),
  (@kermesse_id, @benevole_id, 'benevole',     NULL,       NOW(), NULL);

-- ----------------------------------------------------------------
-- Stands
-- ----------------------------------------------------------------
INSERT INTO `stands` (`kermesse_id`, `name`, `display_order`) VALUES
  (@kermesse_id, 'Stand Buvette E2E', 1),
  (@kermesse_id, 'Stand Jeux E2E',    2);

SET @buvette_id = (SELECT `id` FROM `stands` WHERE `kermesse_id` = @kermesse_id AND `name` = 'Stand Buvette E2E');
SET @jeux_id    = (SELECT `id` FROM `stands` WHERE `kermesse_id` = @kermesse_id AND `name` = 'Stand Jeux E2E');

-- ----------------------------------------------------------------
-- Slots — Buvette has 2 slots (benevole signs up to both); Jeux has 1 (for add-slot test)
-- ----------------------------------------------------------------
INSERT INTO `slots` (`stand_id`, `starts_at`, `ends_at`, `capacity`) VALUES
  (@buvette_id, '2026-10-15 09:00:00', '2026-10-15 12:00:00', 5),
  (@buvette_id, '2026-10-15 14:00:00', '2026-10-15 17:00:00', 5),
  (@jeux_id,    '2026-10-15 10:00:00', '2026-10-15 12:00:00', 3);

SET @slot_buvette_matin  = (SELECT `id` FROM `slots` WHERE `stand_id` = @buvette_id AND `starts_at` = '2026-10-15 09:00:00');
SET @slot_buvette_aprem  = (SELECT `id` FROM `slots` WHERE `stand_id` = @buvette_id AND `starts_at` = '2026-10-15 14:00:00');

-- ----------------------------------------------------------------
-- Signups — benevole has 2 active inscriptions
-- Active = deleted_at IS NULL AND canceled_at IS NULL AND rejected_at IS NULL
-- (status column was dropped in migration 20260619000000)
-- ----------------------------------------------------------------
INSERT INTO `signups` (`slot_id`, `user_id`) VALUES
  (@slot_buvette_matin, @benevole_id),
  (@slot_buvette_aprem, @benevole_id);

-- ----------------------------------------------------------------
-- Magic-link tokens (single-use, far-future expiry)
-- These are consumed by global-setup.ts; e2e.sh re-seeds before each run.
-- ----------------------------------------------------------------
INSERT INTO `access_tokens` (`token_hash`, `token_type`, `user_id`, `email`, `expires_at`) VALUES
  ('430342030be549ef6787d76a2f43874484ef3bd2f357cbcc73164ad229f0b8b0',
   'magic_link', @owner_id,    'owner@e2e.test',    '2030-12-31 23:59:59'),
  ('6312eaf920e18383e1ea309855c9ddf48ec74a005baa78e4000c5abd34877fd4',
   'magic_link', @admin_id,    'admin@e2e.test',    '2030-12-31 23:59:59'),
  ('27f2c891ac51a0a843910713b155b34827fb06e74b705669ac943b0aed0a8834',
   'magic_link', @benevole_id, 'benevole@e2e.test', '2030-12-31 23:59:59');
