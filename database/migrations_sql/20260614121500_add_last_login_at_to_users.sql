-- Migration: add last login tracking to global users.
-- Production migrations must live in database/migrations_sql/ because Ouvaton
-- deploys execute MigrationRunnerService via POST /ops/migrate, not CI4 CLI.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL DEFAULT NULL AFTER `phone`;
