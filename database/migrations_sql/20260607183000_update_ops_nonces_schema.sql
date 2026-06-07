-- Update ops_nonces schema to match the new cross-database DDL format (SQLite compatibility)
-- 1. Remove AUTO_INCREMENT from id so we can drop the primary key
ALTER TABLE ops_nonces MODIFY id BIGINT UNSIGNED NOT NULL;

-- 2. Drop the existing primary key
ALTER TABLE ops_nonces DROP PRIMARY KEY;

-- 3. Drop the id column
ALTER TABLE ops_nonces DROP COLUMN id;

-- 4. Drop the unique constraint on nonce_hash (it will become the primary key)
ALTER TABLE ops_nonces DROP INDEX uq_ops_nonces_hash;

-- 5. Add the new primary key on nonce_hash
ALTER TABLE ops_nonces ADD PRIMARY KEY (nonce_hash);
