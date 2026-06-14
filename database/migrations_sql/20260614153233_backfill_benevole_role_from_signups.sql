-- Migration: backfill benevole role for volunteers who signed up before the fix.
-- Production migrations must live in database/migrations_sql/ because Ouvaton
-- deploys execute MigrationRunnerService via POST /ops/migrate, not CI4 CLI.
--
-- SignupService now inserts a kermesse_user_roles row (role='benevole') on every
-- signup, but existing active signups created before this fix have no such row.
-- Without it, volunteers get unauthorized_role (403) when clicking the magic link
-- from their confirmation email (RoleFilter finds no role for the kermesse).
-- INSERT IGNORE preserves any higher role (owner/admin/gestionnaire) if the user
-- was already a member of the kermesse before signing up as a volunteer.

INSERT IGNORE INTO `kermesse_user_roles` (`kermesse_id`, `user_id`, `role`)
SELECT DISTINCT `st`.`kermesse_id`, `si`.`user_id`, 'benevole'
FROM `signups` `si`
JOIN `slots` `sl` ON `sl`.`id` = `si`.`slot_id`
JOIN `stands` `st` ON `st`.`id` = `sl`.`stand_id`
WHERE `si`.`status` = 'active';
