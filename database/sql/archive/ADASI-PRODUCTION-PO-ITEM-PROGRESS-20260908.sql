-- ============================================================================
-- ADASI Supplier Portal - Production Schema Update
-- Script: ADASI-PRODUCTION-PO-ITEM-PROGRESS-20260908.sql
-- Prepared Date: 2026-09-08
-- Target: Supplier Material Progress Tracking & PO Item Progress Synchronization
-- Associated Migration:
--   2026_09_08_000002_create_po_item_progress_updates_table
--
-- IMPORTANT SAFETY AND EXECUTION INSTRUCTIONS:
-- 1. Take a full database backup before running this script.
-- 2. This script performs additive DDL to create `po_item_progress_updates`.
--    MySQL/MariaDB DDL causes implicit commits.
-- 3. STRICT PREFLIGHT:
--    Parent tables `pr_item_awards`, `purchase_orders`, and `users` must exist.
--    This script includes fail-closed preflight checks.
-- 4. Do NOT execute this script if `php artisan migrate` has already recorded
--    migration `2026_09_08_000002_create_po_item_progress_updates_table`.
-- ============================================================================

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS started_at;

-- ----------------------------------------------------------------------------
-- Step 1: Preflight Verification
-- Ensure parent tables exist before creating child references.
-- ----------------------------------------------------------------------------
SELECT IF(
    (SELECT COUNT(*) FROM `information_schema`.`TABLES`
     WHERE `TABLE_SCHEMA` = DATABASE()
       AND `TABLE_NAME` IN ('pr_item_awards', 'purchase_orders', 'users')) = 3,
    'PREFLIGHT PASSED: Parent tables exist',
    (SELECT `table_name` FROM `information_schema`.`tables` LIMIT 2)
) AS `preflight_parent_tables_status`;

-- Check if table already exists
SELECT COUNT(*) AS `existing_po_item_progress_updates_count`
FROM `information_schema`.`TABLES`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'po_item_progress_updates';

-- ----------------------------------------------------------------------------
-- Step 2: Create Table po_item_progress_updates (Additive, Idempotent)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `po_item_progress_updates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pr_item_award_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'awaiting_confirmation',
    `supplier_controlled_qty_snapshot` INT UNSIGNED NOT NULL DEFAULT 0,
    `estimated_ready_date` DATE NULL DEFAULT NULL,
    `note` TEXT NULL DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `po_item_progress_award_history_idx` (`pr_item_award_id`, `created_at`, `id`),
    INDEX `po_item_progress_status_idx` (`status`),
    INDEX `po_item_progress_updated_by_idx` (`updated_by`),
    CONSTRAINT `fk_po_item_progress_award`
        FOREIGN KEY (`pr_item_award_id`)
        REFERENCES `pr_item_awards` (`id`)
        ON DELETE RESTRICT,
    CONSTRAINT `fk_po_item_progress_updater`
        FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Step 3: Update Laravel Migrations Ledger
-- Record migration batch so `php artisan migrate` remains synchronized.
-- ----------------------------------------------------------------------------
SET @adasi_progress_batch = (
    SELECT COALESCE(MAX(`batch`), 0) + 1
    FROM `migrations`
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_08_000002_create_po_item_progress_updates_table', @adasi_progress_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_08_000002_create_po_item_progress_updates_table'
);

-- ----------------------------------------------------------------------------
-- Step 4: Post-Verification Output
-- Verify table structure, indexes, foreign keys, and migration registration.
-- ----------------------------------------------------------------------------
SHOW COLUMNS FROM `po_item_progress_updates`;
SHOW INDEX FROM `po_item_progress_updates`;

SELECT `TABLE_NAME`, `CONSTRAINT_NAME`, `CONSTRAINT_TYPE`
FROM `information_schema`.`TABLE_CONSTRAINTS`
WHERE `CONSTRAINT_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'po_item_progress_updates';

SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` = '2026_09_08_000002_create_po_item_progress_updates_table';

SELECT CURRENT_TIMESTAMP AS completed_at, 'MIGRATION SUCCESSFUL' AS result;
