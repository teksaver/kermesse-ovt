-- E2E Fixtures — KERMESSE TEST ONLY
-- Idempotent: drops then recreates E2E test data.
-- Tables use no DB prefix (docker-compose sets DBPrefix = "").
--
-- Raw magic-link tokens (single-use, consumed by global-setup.ts):
--   owner    -> e2e-magic-owner-01    hash: 430342030be549ef6787d76a2f43874484ef3bd2f357cbcc73164ad229f0b8b0
--   admin    -> e2e-magic-admin-01    hash: 6312eaf920e18383e1ea309855c9ddf48ec74a005baa78e4000c5abd34877fd4
--   benevole -> e2e-magic-benevole-01 hash: 27f2c891ac51a0a843910713b155b34827fb06e74b705669ac943b0aed0a8834

-- ----------------------------------------------------------------
-- Cleanup (FK-safe order: most-dependent first)
-- Covers all e2e slugs so re-seeding is fully idempotent.
-- ----------------------------------------------------------------
DELETE s FROM `signups` s
  INNER JOIN `slots` sl  ON sl.id  = s.slot_id
  INNER JOIN `stands` st ON st.id  = sl.stand_id
  INNER JOIN `kermesses` k ON k.id = st.kermesse_id
  WHERE k.public_slug LIKE 'kermesse-e2e%';

DELETE FROM `access_tokens` WHERE `email` LIKE '%@e2e.test';

DELETE kur FROM `kermesse_user_roles` kur
  INNER JOIN `kermesses` k ON k.id = kur.kermesse_id
  WHERE k.public_slug LIKE 'kermesse-e2e%';

DELETE sl FROM `slots` sl
  INNER JOIN `stands` st ON st.id  = sl.stand_id
  INNER JOIN `kermesses` k ON k.id = st.kermesse_id
  WHERE k.public_slug LIKE 'kermesse-e2e%';

DELETE st FROM `stands` st
  INNER JOIN `kermesses` k ON k.id = st.kermesse_id
  WHERE k.public_slug LIKE 'kermesse-e2e%';

DELETE FROM `kermesses` WHERE `public_slug` LIKE 'kermesse-e2e%';
DELETE FROM `users` WHERE `email` LIKE '%@e2e.test';

SET @slug        = 'kermesse-e2e-test';
-- Relative event dates so fixtures never expire after a calendar year.
SET @event_date  = DATE_ADD(CURDATE(), INTERVAL 4 MONTH);
SET @prep_date   = DATE_ADD(CURDATE(), INTERVAL 6 MONTH);
SET @closed_date = DATE_SUB(CURDATE(), INTERVAL 1 MONTH);

-- ----------------------------------------------------------------
-- Users
-- ----------------------------------------------------------------
INSERT INTO `users` (`email`, `email_hash`, `first_name`, `last_name`, `phone`) VALUES
  ('owner@e2e.test',        SHA2('owner@e2e.test',        256), 'Alice',  'Owner',  ''),
  ('admin@e2e.test',        SHA2('admin@e2e.test',        256), 'Bob',    'Admin',  ''),
  ('gestionnaire@e2e.test', SHA2('gestionnaire@e2e.test', 256), 'Carl',   'Gestion',''),
  ('benevole@e2e.test',     SHA2('benevole@e2e.test',     256), 'Dana',   'Benev',  ''),
  -- Auxiliary users for specific test scenarios
  ('other@e2e.test',        SHA2('other@e2e.test',        256), 'Eve',    'Other',  ''),
  ('dup-test@e2e.test',     SHA2('dup-test@e2e.test',     256), 'Frank',  'DupTest',''),
  ('overlap-test@e2e.test', SHA2('overlap-test@e2e.test', 256), 'Grace',  'OvTest', '');

SET @owner_id      = (SELECT `id` FROM `users` WHERE `email` = 'owner@e2e.test');
SET @admin_id      = (SELECT `id` FROM `users` WHERE `email` = 'admin@e2e.test');
SET @gestion_id    = (SELECT `id` FROM `users` WHERE `email` = 'gestionnaire@e2e.test');
SET @benevole_id   = (SELECT `id` FROM `users` WHERE `email` = 'benevole@e2e.test');
SET @other_id      = (SELECT `id` FROM `users` WHERE `email` = 'other@e2e.test');
SET @dup_id        = (SELECT `id` FROM `users` WHERE `email` = 'dup-test@e2e.test');
SET @overlap_id    = (SELECT `id` FROM `users` WHERE `email` = 'overlap-test@e2e.test');

-- ----------------------------------------------------------------
-- Kermesse (open so slots can be added)
-- ----------------------------------------------------------------
INSERT INTO `kermesses`
  (`created_by`, `public_slug`, `name`, `event_date`, `location`, `short_description`, `status`)
VALUES
  (@owner_id, @slug, 'Kermesse E2E', @event_date, 'Salle de test', 'Kermesse de test automatisé', 'open');

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
  (@buvette_id, @event_date + INTERVAL 9 HOUR,  @event_date + INTERVAL 12 HOUR, 5),
  (@buvette_id, @event_date + INTERVAL 14 HOUR, @event_date + INTERVAL 17 HOUR, 5),
  (@jeux_id,    @event_date + INTERVAL 10 HOUR, @event_date + INTERVAL 12 HOUR, 3);

SET @slot_buvette_matin  = (SELECT `id` FROM `slots` WHERE `stand_id` = @buvette_id AND `starts_at` = @event_date + INTERVAL 9 HOUR);
SET @slot_buvette_aprem  = (SELECT `id` FROM `slots` WHERE `stand_id` = @buvette_id AND `starts_at` = @event_date + INTERVAL 14 HOUR);

-- ----------------------------------------------------------------
-- Signups — benevole has 2 active inscriptions
-- Active = deleted_at IS NULL AND canceled_at IS NULL AND rejected_at IS NULL
-- (status column was dropped in migration 20260619000000)
--
-- Self-signed: created_by = user_id AND accepted_at = NOW() so the service's
-- needsConfirmation() logic correctly returns false and shows Cancel (not Accept/Refuse).
-- ----------------------------------------------------------------
INSERT INTO `signups` (`slot_id`, `user_id`, `created_by`, `accepted_at`) VALUES
  (@slot_buvette_matin, @benevole_id, @benevole_id, NOW()),
  (@slot_buvette_aprem, @benevole_id, @benevole_id, NOW());

-- ----------------------------------------------------------------
-- Additional stands/slots in the open kermesse (for story 6.4 tests)
-- ----------------------------------------------------------------

-- Stand Complet: 1 slot filled by other@e2e.test (capacity=1, full)
INSERT INTO `stands` (`kermesse_id`, `name`, `display_order`) VALUES
  (@kermesse_id, 'Stand Complet E2E', 3);
SET @complet_id = (SELECT `id` FROM `stands` WHERE `kermesse_id` = @kermesse_id AND `name` = 'Stand Complet E2E');
INSERT INTO `slots` (`stand_id`, `starts_at`, `ends_at`, `capacity`) VALUES
  (@complet_id, @event_date + INTERVAL 7 HOUR, @event_date + INTERVAL 9 HOUR, 1);
SET @slot_complet = (SELECT `id` FROM `slots` WHERE `stand_id` = @complet_id);
INSERT INTO `signups` (`slot_id`, `user_id`, `created_by`, `accepted_at`) VALUES
  (@slot_complet, @other_id, @other_id, NOW());

-- Stand Confirmations: 2 slots with admin-created signups for benevole (for accept/reject tests)
INSERT INTO `stands` (`kermesse_id`, `name`, `display_order`) VALUES
  (@kermesse_id, 'Stand Confirmations E2E', 4);
SET @confirm_id = (SELECT `id` FROM `stands` WHERE `kermesse_id` = @kermesse_id AND `name` = 'Stand Confirmations E2E');
INSERT INTO `slots` (`stand_id`, `starts_at`, `ends_at`, `capacity`) VALUES
  (@confirm_id, @event_date + INTERVAL 20 HOUR, @event_date + INTERVAL 21 HOUR, 5),
  (@confirm_id, @event_date + INTERVAL 21 HOUR, @event_date + INTERVAL 22 HOUR, 5);
SET @slot_accept = (SELECT `id` FROM `slots` WHERE `stand_id` = @confirm_id AND `starts_at` = @event_date + INTERVAL 20 HOUR);
SET @slot_reject = (SELECT `id` FROM `slots` WHERE `stand_id` = @confirm_id AND `starts_at` = @event_date + INTERVAL 21 HOUR);
-- Admin-created signups (accepted_at NULL + created_by = admin_id → triggers needsConfirmation)
INSERT INTO `signups` (`slot_id`, `user_id`, `created_by`, `first_name`, `last_name`, `email`)
VALUES
  (@slot_accept, @benevole_id, @admin_id, 'Dana', 'Benev', 'benevole@e2e.test'),
  (@slot_reject, @benevole_id, @admin_id, 'Dana', 'Benev', 'benevole@e2e.test');

-- Stand Annulation: 1 self-signed slot for the cancel test (isolated from participations test)
INSERT INTO `stands` (`kermesse_id`, `name`, `display_order`) VALUES
  (@kermesse_id, 'Stand Annulation E2E', 5);
SET @annulation_id = (SELECT `id` FROM `stands` WHERE `kermesse_id` = @kermesse_id AND `name` = 'Stand Annulation E2E');
INSERT INTO `slots` (`stand_id`, `starts_at`, `ends_at`, `capacity`) VALUES
  (@annulation_id, @event_date + INTERVAL 18 HOUR, @event_date + INTERVAL 19 HOUR, 5);
SET @slot_annulation = (SELECT `id` FROM `slots` WHERE `stand_id` = @annulation_id);
INSERT INTO `signups` (`slot_id`, `user_id`, `created_by`, `accepted_at`) VALUES
  (@slot_annulation, @benevole_id, @benevole_id, NOW());

-- Stand Doublon: isolated slot for the duplicate-error visitor test.
-- dup-test@e2e.test has a self-signed signup here; submitting again triggers duplicate_signup.
-- Large capacity ensures no other test can fill it, keeping this slot always available.
INSERT INTO `stands` (`kermesse_id`, `name`, `display_order`) VALUES
  (@kermesse_id, 'Stand Doublon E2E', 6);
SET @doublon_id = (SELECT `id` FROM `stands` WHERE `kermesse_id` = @kermesse_id AND `name` = 'Stand Doublon E2E');
INSERT INTO `slots` (`stand_id`, `starts_at`, `ends_at`, `capacity`) VALUES
  (@doublon_id, @event_date + INTERVAL 15 HOUR, @event_date + INTERVAL 16 HOUR, 20);
SET @slot_doublon = (SELECT `id` FROM `slots` WHERE `stand_id` = @doublon_id);
INSERT INTO `signups` (`slot_id`, `user_id`, `created_by`, `accepted_at`) VALUES
  (@slot_doublon, @dup_id, @dup_id, NOW());

-- overlap-test@e2e.test signed up on Buvette Matin (09:00-12:00)
-- → overlap_conflict when trying Stand Jeux (10:00-12:00)
INSERT INTO `signups` (`slot_id`, `user_id`, `created_by`, `accepted_at`) VALUES
  (@slot_buvette_matin, @overlap_id, @overlap_id, NOW());

-- ----------------------------------------------------------------
-- Additional kermesses for lifecycle state tests (story 6.4 AC1)
-- ----------------------------------------------------------------

-- Kermesse in preparation state
INSERT INTO `kermesses`
  (`created_by`, `public_slug`, `name`, `event_date`, `location`, `short_description`, `status`)
VALUES
  (@owner_id, 'kermesse-e2e-prep', 'Kermesse Préparation E2E', @prep_date, 'Salle de test', 'Kermesse en préparation', 'preparation');
SET @kermesse_prep_id = LAST_INSERT_ID();
INSERT INTO `kermesse_user_roles`
  (`kermesse_id`, `user_id`, `role`, `invited_by`, `accepted_at`, `first_access_at`)
VALUES
  (@kermesse_prep_id, @owner_id, 'owner', NULL, NOW(), NOW());

-- Kermesse in closed state (with a stand and a slot to verify they're hidden)
INSERT INTO `kermesses`
  (`created_by`, `public_slug`, `name`, `event_date`, `location`, `short_description`, `status`)
VALUES
  (@owner_id, 'kermesse-e2e-closed', 'Kermesse Clôturée E2E', @closed_date, 'Salle de test', 'Kermesse terminée', 'closed');
SET @kermesse_closed_id = LAST_INSERT_ID();
INSERT INTO `kermesse_user_roles`
  (`kermesse_id`, `user_id`, `role`, `invited_by`, `accepted_at`, `first_access_at`)
VALUES
  (@kermesse_closed_id, @owner_id, 'owner', NULL, NOW(), NOW());
INSERT INTO `stands` (`kermesse_id`, `name`, `display_order`) VALUES
  (@kermesse_closed_id, 'Stand Fermé E2E', 1);
SET @stand_closed_id = LAST_INSERT_ID();
INSERT INTO `slots` (`stand_id`, `starts_at`, `ends_at`, `capacity`) VALUES
  (@stand_closed_id, @closed_date + INTERVAL 9 HOUR, @closed_date + INTERVAL 12 HOUR, 5);

-- ----------------------------------------------------------------
-- Magic-link tokens (single-use, relative expiry: +1 year from seed time)
-- These are consumed by global-setup.ts; e2e.sh re-seeds before each run.
-- ----------------------------------------------------------------
INSERT INTO `access_tokens` (`token_hash`, `token_type`, `user_id`, `email`, `expires_at`) VALUES
  ('430342030be549ef6787d76a2f43874484ef3bd2f357cbcc73164ad229f0b8b0',
   'magic_link', @owner_id,    'owner@e2e.test',    DATE_ADD(NOW(), INTERVAL 1 YEAR)),
  ('6312eaf920e18383e1ea309855c9ddf48ec74a005baa78e4000c5abd34877fd4',
   'magic_link', @admin_id,    'admin@e2e.test',    DATE_ADD(NOW(), INTERVAL 1 YEAR)),
  ('27f2c891ac51a0a843910713b155b34827fb06e74b705669ac943b0aed0a8834',
   'magic_link', @benevole_id, 'benevole@e2e.test', DATE_ADD(NOW(), INTERVAL 1 YEAR));
