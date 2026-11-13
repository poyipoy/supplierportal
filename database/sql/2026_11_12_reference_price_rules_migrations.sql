-- ==============================================================================
-- Production SQL Migration: Reference Price Rules & Removal of Versioning
-- Matching:
--   1. 2026_11_11_000005_create_reference_price_rules_tables
--   2. 2026_11_12_000001_remove_versioning_from_reference_price_rules
--
-- Dialect: MySQL 8.0+ / MariaDB 10.3+ (InnoDB, utf8mb4)
-- ==============================================================================

-- ==============================================================================
-- SCENARIO A: CLEAN DIRECT INSTALLATION (RECOMMENDED FOR FRESH DEPLOYMENT)
-- Use this if neither 2026_11_11_000005 nor 2026_11_12_000001 has been applied
-- yet in Production. It directly creates the final simplified schema without
-- creating and immediately dropping temporary versioning tables.
-- ==============================================================================

START TRANSACTION;

-- 1. Final Table: reference_price_rules (No rule_set_id column, direct unique on category_key)
CREATE TABLE IF NOT EXISTS `reference_price_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_key` VARCHAR(50) NOT NULL,
  `category_name` VARCHAR(100) NOT NULL,
  `dimension_min` INT UNSIGNED NOT NULL,
  `dimension_max` INT UNSIGNED NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ref_rules_category_key_unique` (`category_key`),
  KEY `ref_rules_status_dim_idx` (`status`, `dimension_min`, `dimension_max`),
  KEY `reference_price_rules_created_by_foreign` (`created_by`),
  KEY `reference_price_rules_updated_by_foreign` (`updated_by`),
  CONSTRAINT `reference_price_rules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reference_price_rules_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ref_rules_dimension_range_check` CHECK (`dimension_min` > 0 AND `dimension_max` >= `dimension_min`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Final Table: reference_price_imports (No confirmed_rule_set_id column)
CREATE TABLE IF NOT EXISTS `reference_price_imports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  `source_file_name` VARCHAR(255) NOT NULL,
  `private_storage_path` VARCHAR(255) NOT NULL,
  `source_file_hash` VARCHAR(64) NOT NULL,
  `source_sheet` VARCHAR(50) NOT NULL,
  `import_mode` VARCHAR(20) NOT NULL,
  `status` VARCHAR(30) NOT NULL,
  `parsed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `preview_diff` JSON NULL,
  `validation_errors` JSON NULL,
  `confirmed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `reference_price_imports_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `reference_price_imports_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register both migrations in Laravel's migrations ledger so artisan migrate remains in sync
SET @batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_11_000005_create_reference_price_rules_tables', @batch),
  ('2026_11_12_000001_remove_versioning_from_reference_price_rules', @batch);

COMMIT;


-- ==============================================================================
-- SCENARIO B: SEQUENTIAL UPGRADE (FOR DATABASES THAT ALREADY RAN 2026_11_11_000005)
-- Run this block only if table `reference_price_rule_sets` already exists in
-- production and needs to be transitioned to the unversioned schema.
-- ==============================================================================
/*
START TRANSACTION;

-- 1. Identify active / retained rule_set_id
SET @retained_rule_set_id = (
  SELECT active_rule_set_id 
  FROM reference_price_settings 
  WHERE active_rule_set_id IS NOT NULL 
  ORDER BY id DESC 
  LIMIT 1
);

SET @retained_rule_set_id = COALESCE(@retained_rule_set_id, (
  SELECT id 
  FROM reference_price_rule_sets 
  WHERE status = 'active' 
  ORDER BY activated_at DESC, version_no DESC, id DESC 
  LIMIT 1
));

SET @retained_rule_set_id = COALESCE(@retained_rule_set_id, (
  SELECT id 
  FROM reference_price_rule_sets 
  ORDER BY version_no DESC, id DESC 
  LIMIT 1
));

-- 2. Data Safety: Verify no duplicate category_key in the retained rule set
-- If any row is returned by this query, STOP and resolve duplicates before proceeding:
-- SELECT category_key, COUNT(*) AS cnt 
-- FROM reference_price_rules 
-- WHERE (@retained_rule_set_id IS NULL OR rule_set_id = @retained_rule_set_id)
-- GROUP BY category_key 
-- HAVING cnt > 1;

-- 3. Prune rules that do not belong to the active/retained rule set
DELETE FROM `reference_price_rules`
WHERE @retained_rule_set_id IS NOT NULL 
  AND `rule_set_id` != @retained_rule_set_id;

DELETE FROM `reference_price_rules`
WHERE @retained_rule_set_id IS NULL;

-- 4. Alter reference_price_rules to remove rule_set_id and rebuild indexes
ALTER TABLE `reference_price_rules`
  DROP FOREIGN KEY `reference_price_rules_rule_set_id_foreign`,
  DROP KEY `ref_rules_set_key_unique`,
  DROP KEY `ref_rules_set_status_dim_idx`,
  DROP COLUMN `rule_set_id`,
  ADD UNIQUE KEY `ref_rules_category_key_unique` (`category_key`),
  ADD KEY `ref_rules_status_dim_idx` (`status`, `dimension_min`, `dimension_max`);

-- 5. Alter reference_price_imports to remove confirmed_rule_set_id
ALTER TABLE `reference_price_imports`
  DROP FOREIGN KEY `reference_price_imports_confirmed_rule_set_id_foreign`,
  DROP COLUMN `confirmed_rule_set_id`;

-- 6. Drop legacy versioning tables
DROP TABLE IF EXISTS `reference_price_settings`;
DROP TABLE IF EXISTS `reference_price_rule_sets`;

-- 7. Register the second migration in the migrations table
SET @batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_12_000001_remove_versioning_from_reference_price_rules', @batch);

COMMIT;
*/


-- ==============================================================================
-- ROLLBACK SCRIPT (MANUAL ROLLBACK FOR CLEAN DIRECT STATE)
-- ==============================================================================
/*
START TRANSACTION;

DROP TABLE IF EXISTS `reference_price_imports`;
DROP TABLE IF EXISTS `reference_price_rules`;

DELETE FROM `migrations` WHERE `migration` IN (
  '2026_11_12_000001_remove_versioning_from_reference_price_rules',
  '2026_11_11_000005_create_reference_price_rules_tables'
);

COMMIT;
*/
