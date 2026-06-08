-- Recreate ops_nonces with the cross-database DDL used by OpsAuthFilter.bootstrapNonceTable.
-- DROP + CREATE is safe: ops_nonces stores short-lived anti-replay tokens (10-min TTL).
-- Any in-flight tokens are voided during deployment, which is acceptable because:
--   (a) a fresh activation just ran and will re-authenticate,
--   (b) tokens that expire within 10 min are not worth preserving across a schema change.
-- This migration is idempotent regardless of whether ops_nonces was created by the
-- initial_schema (old format with AUTO_INCREMENT id) or by bootstrapNonceTable (new format).
DROP TABLE IF EXISTS `ops_nonces`;
CREATE TABLE `ops_nonces` (
    `nonce_hash`   VARCHAR(64)  NOT NULL,
    `expires_at`   DATETIME     NOT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`nonce_hash`),
    INDEX `idx_ops_nonces_expires` (`expires_at`)
);
