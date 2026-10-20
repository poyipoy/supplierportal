-- READ ONLY: verification after #1109 or after importing October 19 migrations.
-- No CREATE/ALTER/DROP/INSERT/UPDATE/DELETE/CALL or data repair is performed.
-- Safe to inspect even when phpMyAdmin currently selects information_schema.
-- Expect exactly 5 BASE TABLE rows, all InnoDB, and 5 unique migration names.
-- Ledger rows alone do NOT prove schema completion: inspect SHOW CREATE outputs.

SELECT DATABASE() AS client_selected_database, 'adaw2196_supplierportal' AS inspected_database;

-- Read-only postchecks: expect five tables, complete indexes and one row per name.
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = 'adaw2196_supplierportal'
  AND table_name IN ('oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients', 'oauth_device_codes')
ORDER BY table_name;

SELECT migration, batch FROM `adaw2196_supplierportal`.`migrations`
WHERE migration IN (
    '2026_10_19_172826_create_oauth_auth_codes_table',
    '2026_10_19_172827_create_oauth_access_tokens_table',
    '2026_10_19_172828_create_oauth_refresh_tokens_table',
    '2026_10_19_172829_create_oauth_clients_table',
    '2026_10_19_172830_create_oauth_device_codes_table'
)
ORDER BY migration;

SHOW CREATE TABLE `adaw2196_supplierportal`.`oauth_auth_codes`;
SHOW CREATE TABLE `adaw2196_supplierportal`.`oauth_access_tokens`;
SHOW CREATE TABLE `adaw2196_supplierportal`.`oauth_refresh_tokens`;
SHOW CREATE TABLE `adaw2196_supplierportal`.`oauth_clients`;
SHOW CREATE TABLE `adaw2196_supplierportal`.`oauth_device_codes`;

-- Secondary indexes: expect six non-unique indexes and one unique user_code index,
-- in addition to the five primary keys. Column order matters for the owner index.
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.statistics
WHERE TABLE_SCHEMA = 'adaw2196_supplierportal'
  AND TABLE_NAME IN ('oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients', 'oauth_device_codes')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
