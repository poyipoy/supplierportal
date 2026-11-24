-- ==============================================================================
-- SQL equivalent of the 2026-11-23 migrations (MySQL 8.0+ / MariaDB 10.3+)
-- Idempotent & safe for phpMyAdmin / cPanel (no stored procedures required).
-- Matching:
--   1. 2026_11_23_000001_add_scope_and_delimiter_to_export_presets_table
--
-- Prerequisites:
--   - Table `export_presets` already exists (created by
--     2026_10_07_000002_create_export_presets_table).
--   - Take a full database backup before executing: MySQL DDL statements
--     (ALTER TABLE) trigger implicit commits.
--   - Engine: InnoDB, Charset: utf8mb4, Collation: utf8mb4_unicode_ci.
-- ==============================================================================

-- ==============================================================================
-- 1. 2026_11_23_000001_add_scope_and_delimiter_to_export_presets_table
-- ==============================================================================

-- 1a. Add `scope` column if missing
SET @sql = IF(
  EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'export_presets'
  )
  AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'export_presets'
      AND COLUMN_NAME = 'scope'
  ),
  'ALTER TABLE `export_presets` ADD COLUMN `scope` VARCHAR(16) NOT NULL DEFAULT ''personal'' AFTER `export_key`',
  'SELECT ''Skipped: export_presets.scope already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1b. Add `csv_delimiter` column if missing
SET @sql = IF(
  EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'export_presets'
  )
  AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'export_presets'
      AND COLUMN_NAME = 'csv_delimiter'
  ),
  'ALTER TABLE `export_presets` ADD COLUMN `csv_delimiter` VARCHAR(4) NOT NULL DEFAULT '','' AFTER `format`',
  'SELECT ''Skipped: export_presets.csv_delimiter already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1c. Add composite index `export_presets_export_key_scope_index` if missing
SET @sql = IF(
  EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'export_presets'
  )
  AND NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'export_presets'
      AND INDEX_NAME = 'export_presets_export_key_scope_index'
  ),
  'ALTER TABLE `export_presets` ADD INDEX `export_presets_export_key_scope_index` (`export_key`, `scope`)',
  'SELECT ''Skipped: export_presets_export_key_scope_index already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ==============================================================================
-- Register in Laravel's migrations table (skip if you run `php artisan migrate`)
-- ==============================================================================
SET @batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`)
SELECT `m`.`migration`, @batch
FROM (
  SELECT '2026_11_23_000001_add_scope_and_delimiter_to_export_presets_table' AS `migration`
) AS `m`
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` AS `existing` WHERE `existing`.`migration` = `m`.`migration`
);

-- ==============================================================================
-- ROLLBACK (manual)
-- Note: Dropping `scope` / `csv_delimiter` removes shared preset scoping and
-- saved CSV delimiter settings on `export_presets`.
-- ==============================================================================
/*
ALTER TABLE `export_presets`
  DROP INDEX `export_presets_export_key_scope_index`,
  DROP COLUMN `scope`,
  DROP COLUMN `csv_delimiter`;

DELETE FROM `migrations`
WHERE `migration` = '2026_11_23_000001_add_scope_and_delimiter_to_export_presets_table';
*/
