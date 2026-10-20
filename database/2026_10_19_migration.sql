-- ADASI Supplier Portal: forward SQL for ALL 5 migrations dated 19 October 2026.
-- Generated on 20 October 2026 from the current repository (HEAD 1a830c0).
-- Target dump: adaw2196_supplierportal (4).sql, MariaDB 11.4.13-MariaDB-cll-lve.
-- Dump SHA256: 0cbefe1c00be6b7715e17b39e2fa288701af9960ee87788863bc6684ecd1a7d3
-- The dump has NONE of these five OAuth tables or five migration ledger names.
--
-- Generator did not execute this SQL. User reported #1109 on a postcheck:
-- run 2026_10_19_verify_only.sql FIRST; do NOT blindly reimport this DDL file.
-- Prior CREATE/ALTER/ledger stages may already have completed.
-- Before import: take a fresh backup, rehearse on restored staging, pause writes
-- and OAuth issuance/workers, and stop concurrent Laravel migrations/imports.
-- Use a client supporting DELIMITER (e.g. phpMyAdmin SQL import) and an account
-- with CREATE/ALTER TABLE, SELECT/INSERT migrations, CREATE/EXECUTE/DROP ROUTINE.
-- All application objects/postchecks are explicitly database-qualified so they
-- do not depend on phpMyAdmin's selected schema. For an isolated staging copy,
-- replace EVERY occurrence of adaw2196_supplierportal with its staging name.
--
-- Run this OR the five PHP migrations, not both. Earlier dates are NOT included.
-- Existing copied ledger entries are retained; missing names are logged only
-- after their CREATE TABLE + indexes succeed. Batch is live MAX(batch) + 1.
-- This is a baseline-only script: if ANY target table exists, it stops rather
-- than guessing its shape or silently modifying/dropping existing OAuth data.
-- It intentionally refuses a second run or partial-state retry. Reconcile the
-- actual schema and ledger before preparing a narrower recovery script.
--
-- UUID columns use CHAR(36), exactly as Laravel's MySqlGrammar compiles these
-- migrations; this is valid on MariaDB 11.4. The separate Laravel mariadb driver
-- can compile UUID natively. This script deliberately uses the portable mysql
-- representation consistently for both oauth_clients.id and client_id columns.
-- foreignId/foreignUuid alone declare TYPES, not FK constraints. No FK is added.
-- nullableMorphs(owner) uses the repository's default numeric BIGINT UNSIGNED.
-- No clients, secrets, keys, tokens, business rows, or SSO configuration created.
--
-- DDL implicitly commits. There is NO whole-script transaction/automatic rollback.
-- On any error, STOP: earlier tables/indexes/ledger rows may already exist.
-- Never continue import with a force/ignore-errors option.
-- No destructive down/rollback script is included: dropping OAuth tables can
-- invalidate active sessions/codes/tokens and must be separately reviewed.
-- Temporary helper routine below is the ONLY object dropped by this script.

USE `adaw2196_supplierportal`;

SELECT DATABASE() AS selected_database, VERSION() AS server_version;

SET @adasi_20261019_previous_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = IF(
    FIND_IN_SET('STRICT_TRANS_TABLES', @adasi_20261019_previous_sql_mode) > 0
    OR FIND_IN_SET('STRICT_ALL_TABLES', @adasi_20261019_previous_sql_mode) > 0,
    @adasi_20261019_previous_sql_mode,
    CONCAT_WS(',', NULLIF(@adasi_20261019_previous_sql_mode, ''), 'STRICT_TRANS_TABLES')
);

-- Strict mode is set BEFORE routine creation because routines capture this mode.
DROP PROCEDURE IF EXISTS `adaw2196_supplierportal`.`adasi_migrate_20261019_oauth`;

DELIMITER $$
CREATE PROCEDURE `adaw2196_supplierportal`.`adasi_migrate_20261019_oauth`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_batch INT;

    IF DATABASE() IS NULL OR DATABASE() <> 'adaw2196_supplierportal' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wrong database: expected adaw2196_supplierportal.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'adaw2196_supplierportal' AND table_name = 'migrations'
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Expected an existing InnoDB Laravel migrations table.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM `adaw2196_supplierportal`.`migrations`
        WHERE migration IN (
        '2026_10_19_172826_create_oauth_auth_codes_table',
        '2026_10_19_172827_create_oauth_access_tokens_table',
        '2026_10_19_172828_create_oauth_refresh_tokens_table',
        '2026_10_19_172829_create_oauth_clients_table',
        '2026_10_19_172830_create_oauth_device_codes_table'
        )
        GROUP BY migration HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate OAuth migration names in ledger; reconcile first.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'adaw2196_supplierportal'
          AND table_name IN ('oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients', 'oauth_device_codes')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An OAuth target table already exists; inspect schema before retry.';
    END IF;

    -- Compute before the first application DDL. Do not hardcode the dump's batch.
    SELECT COALESCE(MAX(batch), 0) + 1 INTO v_batch FROM `adaw2196_supplierportal`.`migrations`;

    -- 2026_10_19_172826_create_oauth_auth_codes_table
    create table `adaw2196_supplierportal`.`oauth_auth_codes` (
        `id` char(80) not null,
        `user_id` bigint unsigned not null,
        `client_id` char(36) not null,
        `scopes` text null,
        `revoked` tinyint(1) not null,
        `expires_at` datetime null,
        primary key (`id`)
    ) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
    alter table `adaw2196_supplierportal`.`oauth_auth_codes` add index `oauth_auth_codes_user_id_index`(`user_id`);
    INSERT INTO `adaw2196_supplierportal`.`migrations` (migration, batch)
    SELECT '2026_10_19_172826_create_oauth_auth_codes_table', v_batch
    WHERE NOT EXISTS (
        SELECT 1 FROM `adaw2196_supplierportal`.`migrations` WHERE migration = '2026_10_19_172826_create_oauth_auth_codes_table'
    );

    -- 2026_10_19_172827_create_oauth_access_tokens_table
    create table `adaw2196_supplierportal`.`oauth_access_tokens` (
        `id` char(80) not null,
        `user_id` bigint unsigned null,
        `client_id` char(36) not null,
        `name` varchar(255) null,
        `scopes` text null,
        `revoked` tinyint(1) not null,
        `created_at` timestamp null,
        `updated_at` timestamp null,
        `expires_at` datetime null,
        primary key (`id`)
    ) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
    alter table `adaw2196_supplierportal`.`oauth_access_tokens` add index `oauth_access_tokens_user_id_index`(`user_id`);
    INSERT INTO `adaw2196_supplierportal`.`migrations` (migration, batch)
    SELECT '2026_10_19_172827_create_oauth_access_tokens_table', v_batch
    WHERE NOT EXISTS (
        SELECT 1 FROM `adaw2196_supplierportal`.`migrations` WHERE migration = '2026_10_19_172827_create_oauth_access_tokens_table'
    );

    -- 2026_10_19_172828_create_oauth_refresh_tokens_table
    create table `adaw2196_supplierportal`.`oauth_refresh_tokens` (
        `id` char(80) not null,
        `access_token_id` char(80) not null,
        `revoked` tinyint(1) not null,
        `expires_at` datetime null,
        primary key (`id`)
    ) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
    alter table `adaw2196_supplierportal`.`oauth_refresh_tokens` add index `oauth_refresh_tokens_access_token_id_index`(`access_token_id`);
    INSERT INTO `adaw2196_supplierportal`.`migrations` (migration, batch)
    SELECT '2026_10_19_172828_create_oauth_refresh_tokens_table', v_batch
    WHERE NOT EXISTS (
        SELECT 1 FROM `adaw2196_supplierportal`.`migrations` WHERE migration = '2026_10_19_172828_create_oauth_refresh_tokens_table'
    );

    -- 2026_10_19_172829_create_oauth_clients_table
    create table `adaw2196_supplierportal`.`oauth_clients` (
        `id` char(36) not null,
        `owner_type` varchar(255) null,
        `owner_id` bigint unsigned null,
        `name` varchar(255) not null,
        `secret` varchar(255) null,
        `provider` varchar(255) null,
        `redirect_uris` text not null,
        `grant_types` text not null,
        `revoked` tinyint(1) not null,
        `created_at` timestamp null,
        `updated_at` timestamp null,
        primary key (`id`)
    ) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
    alter table `adaw2196_supplierportal`.`oauth_clients` add index `oauth_clients_owner_type_owner_id_index`(`owner_type`, `owner_id`);
    INSERT INTO `adaw2196_supplierportal`.`migrations` (migration, batch)
    SELECT '2026_10_19_172829_create_oauth_clients_table', v_batch
    WHERE NOT EXISTS (
        SELECT 1 FROM `adaw2196_supplierportal`.`migrations` WHERE migration = '2026_10_19_172829_create_oauth_clients_table'
    );

    -- 2026_10_19_172830_create_oauth_device_codes_table
    create table `adaw2196_supplierportal`.`oauth_device_codes` (
        `id` char(80) not null,
        `user_id` bigint unsigned null,
        `client_id` char(36) not null,
        `user_code` char(8) not null,
        `scopes` text not null,
        `revoked` tinyint(1) not null,
        `user_approved_at` datetime null,
        `last_polled_at` datetime null,
        `expires_at` datetime null,
        primary key (`id`)
    ) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
    alter table `adaw2196_supplierportal`.`oauth_device_codes` add index `oauth_device_codes_user_id_index`(`user_id`);
    alter table `adaw2196_supplierportal`.`oauth_device_codes` add index `oauth_device_codes_client_id_index`(`client_id`);
    alter table `adaw2196_supplierportal`.`oauth_device_codes` add unique `oauth_device_codes_user_code_unique`(`user_code`);
    INSERT INTO `adaw2196_supplierportal`.`migrations` (migration, batch)
    SELECT '2026_10_19_172830_create_oauth_device_codes_table', v_batch
    WHERE NOT EXISTS (
        SELECT 1 FROM `adaw2196_supplierportal`.`migrations` WHERE migration = '2026_10_19_172830_create_oauth_device_codes_table'
    );

    SELECT 'Completed all five OAuth migration stages' AS result, v_batch AS batch_for_new_entries;
END$$
DELIMITER ;

CALL `adaw2196_supplierportal`.`adasi_migrate_20261019_oauth`();
DROP PROCEDURE `adaw2196_supplierportal`.`adasi_migrate_20261019_oauth`;

SET SESSION sql_mode = @adasi_20261019_previous_sql_mode;

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
