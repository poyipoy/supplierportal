-- SQL equivalent of the 2026-11-06 migrations (MySQL).
-- Apply in order. Run `down` blocks manually to roll back.

START TRANSACTION;

-- ============================================================
-- 2026_11_06_000001_add_import_timezone_to_user_preferences_table
-- ============================================================
ALTER TABLE `user_preferences`
  ADD COLUMN `country_code` CHAR(2) NULL AFTER `timezone`,
  ADD COLUMN `import_timezone` VARCHAR(64) NULL AFTER `country_code`;

-- ============================================================
-- 2026_11_06_000002_create_maintenance_records_tables
-- ============================================================
CREATE TABLE `maintenance_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('global','module') NOT NULL,
  `status` ENUM('draft','scheduled','active','completed') NOT NULL DEFAULT 'draft',
  `title` VARCHAR(150) NOT NULL,
  `description` TEXT NOT NULL,
  `starts_at` DATETIME NULL,
  `estimated_end_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_records_status_start_idx` (`status`, `starts_at`),
  KEY `maintenance_records_created_by_foreign` (`created_by`),
  KEY `maintenance_records_updated_by_foreign` (`updated_by`),
  CONSTRAINT `maintenance_records_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_records_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `maintenance_records` ADD CONSTRAINT `maintenance_records_window_chk`
  CHECK (starts_at IS NULL OR estimated_end_at IS NULL OR estimated_end_at > starts_at);
ALTER TABLE `maintenance_records` ADD CONSTRAINT `maintenance_records_schedule_chk`
  CHECK (status = 'draft' OR (starts_at IS NOT NULL AND estimated_end_at IS NOT NULL));
ALTER TABLE `maintenance_records` ADD CONSTRAINT `maintenance_records_completed_chk`
  CHECK ((status = 'completed' AND completed_at IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL));

CREATE TABLE `maintenance_record_modules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `maintenance_record_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(64) NOT NULL,
  `deactivated_at` DATETIME NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_record_modules_record_key_unique` (`maintenance_record_id`, `module_key`),
  KEY `maintenance_record_modules_key_idx` (`module_key`, `deactivated_at`),
  CONSTRAINT `maintenance_record_modules_maintenance_record_id_foreign` FOREIGN KEY (`maintenance_record_id`) REFERENCES `maintenance_records` (`id`) ON DELETE RESTRICT
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Register in Laravel's migrations table (skip if you run `php artisan migrate` instead).
SET @batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_06_000001_add_import_timezone_to_user_preferences_table', @batch),
  ('2026_11_06_000002_create_maintenance_records_tables', @batch);

COMMIT;

-- ============================================================
-- ROLLBACK (manual, reverse order)
-- Migration 000002 refuses rollback if any maintenance_records exist:
--   SELECT COUNT(*) FROM maintenance_records;  -- must be 0
-- ============================================================
-- DROP TABLE IF EXISTS `maintenance_record_modules`;
-- DROP TABLE IF EXISTS `maintenance_records`;
-- ALTER TABLE `user_preferences` DROP COLUMN `country_code`, DROP COLUMN `import_timezone`;
-- DELETE FROM `migrations` WHERE `migration` IN (
--   '2026_11_06_000002_create_maintenance_records_tables',
--   '2026_11_06_000001_add_import_timezone_to_user_preferences_table');
