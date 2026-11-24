-- ==============================================================================
-- SQL equivalent of the 2026-11-24 migrations (MySQL 8.0+ / MariaDB 10.3+)
-- Idempotent & safe for phpMyAdmin / cPanel (no stored procedures required).
-- Matching:
--   1. 2026_11_24_000001_add_cancelled_state_to_purchase_requisitions_table
--   2. 2026_11_24_000002_add_cancellation_columns_to_purchase_requisition_suppliers_table
--   3. 2026_11_24_000003_add_reason_to_pr_supplier_invitation_events_table
--
-- Prerequisites:
--   - Tables `purchase_requisitions`, `purchase_requisition_suppliers`, and
--     `pr_supplier_invitation_events` already exist.
--   - Take a full database backup before executing: MySQL DDL statements
--     (ALTER TABLE) trigger implicit commits.
--   - Engine: InnoDB, Charset: utf8mb4, Collation: utf8mb4_unicode_ci.
-- ==============================================================================

-- ==============================================================================
-- 1. 2026_11_24_000001_add_cancelled_state_to_purchase_requisitions_table
-- ==============================================================================

-- 1a. Ensure `status` ENUM includes 'cancelled' (idempotent)
ALTER TABLE `purchase_requisitions`
  MODIFY COLUMN `status` ENUM('draft', 'submitted', 'rejected', 'bidding', 'completed', 'cancelled') NOT NULL DEFAULT 'draft';

-- 1b. Add `cancelled_at` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisitions'
      AND COLUMN_NAME = 'cancelled_at'
  ),
  'ALTER TABLE `purchase_requisitions` ADD COLUMN `cancelled_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`',
  'SELECT ''Skipped: purchase_requisitions.cancelled_at already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1c. Add `cancelled_by` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisitions'
      AND COLUMN_NAME = 'cancelled_by'
  ),
  'ALTER TABLE `purchase_requisitions` ADD COLUMN `cancelled_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `cancelled_at`',
  'SELECT ''Skipped: purchase_requisitions.cancelled_by already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1d. Add `cancellation_reason` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisitions'
      AND COLUMN_NAME = 'cancellation_reason'
  ),
  'ALTER TABLE `purchase_requisitions` ADD COLUMN `cancellation_reason` TEXT NULL AFTER `cancelled_by`',
  'SELECT ''Skipped: purchase_requisitions.cancellation_reason already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1e. Add FK `purchase_requisitions_cancelled_by_foreign` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisitions'
      AND CONSTRAINT_NAME = 'purchase_requisitions_cancelled_by_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
  ),
  'ALTER TABLE `purchase_requisitions` ADD CONSTRAINT `purchase_requisitions_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT ''Skipped: purchase_requisitions_cancelled_by_foreign already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ==============================================================================
-- 2. 2026_11_24_000002_add_cancellation_columns_to_purchase_requisition_suppliers_table
-- ==============================================================================

-- 2a. Add `cancelled_at` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisition_suppliers'
      AND COLUMN_NAME = 'cancelled_at'
  ),
  'ALTER TABLE `purchase_requisition_suppliers` ADD COLUMN `cancelled_at` TIMESTAMP NULL DEFAULT NULL AFTER `invited_at`',
  'SELECT ''Skipped: purchase_requisition_suppliers.cancelled_at already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2b. Add `cancelled_by` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisition_suppliers'
      AND COLUMN_NAME = 'cancelled_by'
  ),
  'ALTER TABLE `purchase_requisition_suppliers` ADD COLUMN `cancelled_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `cancelled_at`',
  'SELECT ''Skipped: purchase_requisition_suppliers.cancelled_by already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2c. Add `cancellation_reason` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisition_suppliers'
      AND COLUMN_NAME = 'cancellation_reason'
  ),
  'ALTER TABLE `purchase_requisition_suppliers` ADD COLUMN `cancellation_reason` TEXT NULL AFTER `cancelled_by`',
  'SELECT ''Skipped: purchase_requisition_suppliers.cancellation_reason already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2d. Add FK `purchase_requisition_suppliers_cancelled_by_foreign` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisition_suppliers'
      AND CONSTRAINT_NAME = 'purchase_requisition_suppliers_cancelled_by_foreign'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
  ),
  'ALTER TABLE `purchase_requisition_suppliers` ADD CONSTRAINT `purchase_requisition_suppliers_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT ''Skipped: purchase_requisition_suppliers_cancelled_by_foreign already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2e. Add index `pr_suppliers_supplier_cancelled_idx` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_requisition_suppliers'
      AND INDEX_NAME = 'pr_suppliers_supplier_cancelled_idx'
  ),
  'ALTER TABLE `purchase_requisition_suppliers` ADD INDEX `pr_suppliers_supplier_cancelled_idx` (`supplier_id`, `cancelled_at`)',
  'SELECT ''Skipped: pr_suppliers_supplier_cancelled_idx already exists'' AS `migration_status`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ==============================================================================
-- 3. 2026_11_24_000003_add_reason_to_pr_supplier_invitation_events_table
-- ==============================================================================

-- 3a. Add `reason` if missing
SET @sql = IF(
  NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pr_supplier_invitation_events'
      AND COLUMN_NAME = 'reason'
  ),
  'ALTER TABLE `pr_supplier_invitation_events` ADD COLUMN `reason` TEXT NULL AFTER `event`',
  'SELECT ''Skipped: pr_supplier_invitation_events.reason already exists'' AS `migration_status`'
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
  SELECT '2026_11_24_000001_add_cancelled_state_to_purchase_requisitions_table' AS `migration`
  UNION ALL SELECT '2026_11_24_000002_add_cancellation_columns_to_purchase_requisition_suppliers_table'
  UNION ALL SELECT '2026_11_24_000003_add_reason_to_pr_supplier_invitation_events_table'
) AS `m`
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` AS `existing` WHERE `existing`.`migration` = `m`.`migration`
);

-- ==============================================================================
-- ROLLBACK (manual, reverse order)
-- Refuses rollback if any of these queries return > 0:
--   SELECT COUNT(*) FROM `pr_supplier_invitation_events` WHERE `reason` IS NOT NULL;
--   SELECT COUNT(*) FROM `purchase_requisition_suppliers` WHERE `cancelled_at` IS NOT NULL;
--   SELECT COUNT(*) FROM `purchase_requisitions` WHERE `status` = 'cancelled';
-- ==============================================================================
/*
-- 3. Rollback 2026_11_24_000003
ALTER TABLE `pr_supplier_invitation_events`
  DROP COLUMN `reason`;

-- 2. Rollback 2026_11_24_000002
ALTER TABLE `purchase_requisition_suppliers`
  DROP INDEX `pr_suppliers_supplier_cancelled_idx`,
  DROP FOREIGN KEY `purchase_requisition_suppliers_cancelled_by_foreign`,
  DROP KEY `purchase_requisition_suppliers_cancelled_by_foreign`,
  DROP COLUMN `cancellation_reason`,
  DROP COLUMN `cancelled_by`,
  DROP COLUMN `cancelled_at`;

-- 1. Rollback 2026_11_24_000001
ALTER TABLE `purchase_requisitions`
  DROP FOREIGN KEY `purchase_requisitions_cancelled_by_foreign`,
  DROP KEY `purchase_requisitions_cancelled_by_foreign`,
  DROP COLUMN `cancellation_reason`,
  DROP COLUMN `cancelled_by`,
  DROP COLUMN `cancelled_at`,
  MODIFY COLUMN `status` ENUM('draft', 'submitted', 'rejected', 'bidding', 'completed') NOT NULL DEFAULT 'draft';

DELETE FROM `migrations` WHERE `migration` IN (
  '2026_11_24_000003_add_reason_to_pr_supplier_invitation_events_table',
  '2026_11_24_000002_add_cancellation_columns_to_purchase_requisition_suppliers_table',
  '2026_11_24_000001_add_cancelled_state_to_purchase_requisitions_table'
);
*/
