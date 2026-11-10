-- ADASI Supplier Portal production database update
-- Date: 2026-08-23
-- Source: local `adasi_portal` migration history and schema.
--
-- Import this file after selecting the production application database.
-- This version intentionally uses only direct SQL statements. It does not
-- access information_schema, use PREPARE, create routines, or use DELIMITER,
-- because restricted cPanel database users may reject those operations.
--
-- Expected production state: the three migrations dated 2026-08-23 have not
-- been applied yet. Take a backup and use a low-traffic window before import.
-- MySQL/MariaDB DDL is not transactionally atomic across all statements.

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS started_at;

-- 2026_08_23_000001 and 2026_08_23_000002
ALTER TABLE `export_jobs`
    ADD COLUMN `progress_stage` VARCHAR(32) NOT NULL DEFAULT 'queued' AFTER `status`,
    ADD COLUMN `progress` TINYINT UNSIGNED NULL AFTER `progress_stage`,
    ADD COLUMN `total_rows` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `progress`,
    ADD COLUMN `processed_rows` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_rows`,
    ADD COLUMN `processed_chunks` JSON NULL AFTER `processed_rows`;

UPDATE `export_jobs`
SET `progress_stage` = 'generating'
WHERE `status` = 'processing';

UPDATE `export_jobs`
SET `progress_stage` = 'completed',
    `progress` = 100
WHERE `status` = 'completed';

UPDATE `export_jobs`
SET `progress_stage` = 'failed'
WHERE `status` = 'failed';

UPDATE `export_jobs`
SET `processed_chunks` = JSON_ARRAY()
WHERE `status` = 'completed'
  AND `processed_chunks` IS NULL;

-- 2026_08_23_000003
-- These four secondary indexes are covered by retained equivalent or unique
-- indexes verified from the local database schema.
ALTER TABLE `quotations`
    DROP INDEX `quotations_submitted_at_index`;

ALTER TABLE `purchase_requisitions`
    DROP INDEX `pr_number_index`;

ALTER TABLE `purchase_orders`
    DROP INDEX `purchase_orders_po_number_index`;

ALTER TABLE `po_quotations`
    DROP INDEX `po_quotations_po_id_index`;

-- Keep Laravel's migration repository consistent when this file is imported
-- instead of running `php artisan migrate`.
SET @adasi_perf_batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_23_000001_add_progress_to_export_jobs_table', @adasi_perf_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_23_000001_add_progress_to_export_jobs_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_23_000002_add_row_progress_to_export_jobs_table', @adasi_perf_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_23_000002_add_row_progress_to_export_jobs_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_23_000003_remove_verified_redundant_indexes', @adasi_perf_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_23_000003_remove_verified_redundant_indexes'
);

-- Verification output. The four dropped index names should no longer appear
-- in SHOW INDEX results; retained unique indexes should remain present.
SHOW COLUMNS FROM `export_jobs`;
SHOW INDEX FROM `quotations`;
SHOW INDEX FROM `purchase_requisitions`;
SHOW INDEX FROM `purchase_orders`;
SHOW INDEX FROM `po_quotations`;

SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` LIKE '2026_08_23_%'
ORDER BY `migration`;

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS completed_at;
