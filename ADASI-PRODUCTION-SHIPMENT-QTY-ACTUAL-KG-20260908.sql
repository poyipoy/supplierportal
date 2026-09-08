-- ============================================================================
-- ADASI Supplier Portal - Production Schema Update
-- Script: ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql
-- Prepared Date: 2026-09-08
-- Target: Shipment Qty-Based Fulfillment & Actual Kg Revision
-- Associated Migration:
--   2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight
--
-- IMPORTANT SAFETY AND EXECUTION INSTRUCTIONS:
-- 1. Take a full database backup before running this script.
-- 2. This script performs DDL modifications on `shipment_items`. MySQL/MariaDB
--    DDL statements cause implicit commits and are not rollbackable via transaction.
-- 3. STRICT PREFLIGHT:
--    Production shipment_items table MUST be completely empty (0 rows).
--    If rows exist, DO NOT RUN. Semantic conversion between Kg and Qty is invalid.
--    This script includes a fail-closed preflight check.
-- 4. Do NOT execute this script if `php artisan migrate` has already been run for
--    migration `2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight`.
-- ============================================================================

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS started_at;

-- ----------------------------------------------------------------------------
-- Step 1: Preflight Verification
-- Ensure shipment_items exists and has 0 rows.
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS `shipment_items_row_count` FROM `shipment_items`;

-- Fail-closed assertion: If shipment_items has > 0 rows, throw an error via subquery
SELECT IF(
    (SELECT COUNT(*) FROM `shipment_items`) > 0,
    (SELECT `table_name` FROM `information_schema`.`tables` LIMIT 2),
    'PREFLIGHT PASSED: shipment_items is empty'
) AS `preflight_status`;

-- Verify column shipped_quantity exists
SELECT `COLUMN_NAME`, `DATA_TYPE`
FROM `information_schema`.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'shipment_items'
  AND `COLUMN_NAME` = 'shipped_quantity';

-- ----------------------------------------------------------------------------
-- Step 2: Drop Old Check Constraint and Column
-- ----------------------------------------------------------------------------
-- Drop old check constraint if present
ALTER TABLE `shipment_items`
    DROP CHECK `shipment_items_shipped_quantity_positive`;

-- Drop old decimal shipped_quantity column
ALTER TABLE `shipment_items`
    DROP COLUMN `shipped_quantity`;

-- ----------------------------------------------------------------------------
-- Step 3: Add New Columns (shipped_qty, actual_weight_kg)
-- ----------------------------------------------------------------------------
ALTER TABLE `shipment_items`
    ADD COLUMN `shipped_qty` INT UNSIGNED NOT NULL AFTER `pr_item_award_id`,
    ADD COLUMN `actual_weight_kg` DECIMAL(12,4) NOT NULL AFTER `shipped_qty`;

-- ----------------------------------------------------------------------------
-- Step 4: Add New Integrity Constraints
-- ----------------------------------------------------------------------------
ALTER TABLE `shipment_items`
    ADD CONSTRAINT `shipment_items_shipped_qty_positive`
        CHECK (`shipped_qty` > 0);

ALTER TABLE `shipment_items`
    ADD CONSTRAINT `shipment_items_actual_weight_kg_positive`
        CHECK (`actual_weight_kg` > 0.0000);

-- ----------------------------------------------------------------------------
-- Step 5: Update Laravel Migrations Table
-- Record migration batch so php artisan migrate remains synchronized.
-- ----------------------------------------------------------------------------
SET @adasi_schema_batch = (
    SELECT COALESCE(MAX(`batch`), 0) + 1
    FROM `migrations`
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight', @adasi_schema_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight'
);

-- ----------------------------------------------------------------------------
-- Step 6: Post-Verification Output
-- ----------------------------------------------------------------------------
SHOW COLUMNS FROM `shipment_items`;
SHOW INDEX FROM `shipment_items`;

SELECT `TABLE_NAME`, `CONSTRAINT_NAME`, `CHECK_CLAUSE`
FROM `information_schema`.`CHECK_CONSTRAINTS`
WHERE `CONSTRAINT_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'shipment_items';

SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` = '2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight';

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS completed_at;
