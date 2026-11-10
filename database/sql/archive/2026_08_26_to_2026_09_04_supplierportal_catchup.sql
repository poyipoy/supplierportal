-- ADASI Supplier Portal migration catch-up for adaw2196_supplierportal.sql
-- Prepared: 2026-09-07
--
-- This file repairs the supplied dump's migration/schema drift from:
--   2026_08_18_000001 through 2026_09_04_000003
--
-- Evidence from the supplied dump before running this file:
--   * migrations is recorded through 2026_08_23_000003, but the ledger omits
--     2026_08_18_000001 and 2026_08_20_000001 even though their schema exists;
--   * export_jobs already contains the cancelled status;
--   * quotation_items already contains the offer/availability columns from
--     2026_08_28_000002, so those columns are intentionally not added again;
--   * auth_known_devices, pr_item_awards, and shipment tables are absent;
--   * the 2026-09-03/04 indexes, enum values, columns, and constraints are
--     absent.
--
-- Run against a restored copy first, after selecting the application database.
-- Take a backup before production execution. MySQL/MariaDB DDL is not
-- transactionally atomic across all statements. Do not run this file together
-- with php artisan migrate for the same migrations.

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS started_at;

-- Ledger-only reconciliation for schema already present in the supplied dump:
--   2026_08_18_000001_create_export_jobs_table
--   2026_08_20_000001_make_period_month_nullable_for_annual_periods
-- export_jobs exists with its later progress fields, and periods.month is
-- already an unsigned nullable TINYINT. Their ledger rows are inserted below
-- only after all catch-up schema statements complete successfully.

-- 2026_08_26_000001_add_cancelled_status_to_export_jobs
-- The supplied dump already has this enum value; this normalizes the column
-- and allows the migration ledger to match the effective schema.
ALTER TABLE `export_jobs`
    MODIFY COLUMN `status`
        ENUM('queued','processing','completed','failed','cancelled')
        NOT NULL DEFAULT 'queued';

-- 2026_08_28_000001_create_auth_known_devices_table
CREATE TABLE `auth_known_devices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `device_hash` CHAR(64) NOT NULL,
    `last_ip_address` VARCHAR(45) NULL,
    `last_user_agent` VARCHAR(512) NULL,
    `first_seen_at` TIMESTAMP NOT NULL,
    `last_seen_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `auth_known_devices_user_id_device_hash_unique` (`user_id`, `device_hash`),
    KEY `auth_known_devices_user_id_foreign` (`user_id`),
    CONSTRAINT `auth_known_devices_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2026_08_28_000002_add_offer_fields_to_quotation_items_table
-- The five offer/availability columns already exist in the supplied dump,
-- matching the Schema::hasColumn guards in the PHP migration. Only the
-- price definition needs normalization here.
ALTER TABLE `quotation_items`
    MODIFY COLUMN `price_per_kg` DECIMAL(15,4) NULL DEFAULT NULL;

-- 2026_09_03_000001_ensure_quotation_items_price_per_kg_is_nullable
-- This is intentionally repeated because it is a separate migration and is
-- harmless after the previous normalization.
ALTER TABLE `quotation_items`
    MODIFY COLUMN `price_per_kg` DECIMAL(15,4) NULL DEFAULT NULL;

-- 2026_09_03_000002_add_unique_quotation_id_to_po_quotations_table
-- This result must be empty before the unique index is added. The ALTER TABLE
-- below also fails natively if duplicate quotation_id rows remain.
SELECT `quotation_id`, COUNT(*) AS `total`
FROM `po_quotations`
GROUP BY `quotation_id`
HAVING COUNT(*) > 1;

ALTER TABLE `po_quotations`
    ADD UNIQUE KEY `po_quotations_quotation_id_unique` (`quotation_id`);

-- 2026_09_03_000003_add_all_unavailable_to_quotation_status_enum
ALTER TABLE `quotations`
    MODIFY COLUMN `status`
        ENUM('draft','submitted','revision_requested','accepted','rejected','all_unavailable')
        DEFAULT 'draft';

-- 2026_09_04_000001_create_pr_item_awards_table
CREATE TABLE `pr_item_awards` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pr_id` BIGINT UNSIGNED NOT NULL,
    `pr_item_id` BIGINT UNSIGNED NOT NULL,
    `quotation_id` BIGINT UNSIGNED NOT NULL,
    `quotation_item_id` BIGINT UNSIGNED NOT NULL,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `purchase_order_id` BIGINT UNSIGNED NULL,
    `awarded_by` BIGINT UNSIGNED NOT NULL,
    `awarded_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `pr_item_awards_pr_item_id_unique` (`pr_item_id`),
    KEY `pr_item_awards_pr_id_supplier_id_index` (`pr_id`, `supplier_id`),
    KEY `pr_item_awards_quotation_id_supplier_id_index` (`quotation_id`, `supplier_id`),
    KEY `pr_item_awards_purchase_order_id_index` (`purchase_order_id`),
    KEY `pr_item_awards_quotation_item_id_foreign` (`quotation_item_id`),
    KEY `pr_item_awards_supplier_id_foreign` (`supplier_id`),
    KEY `pr_item_awards_awarded_by_foreign` (`awarded_by`),
    CONSTRAINT `pr_item_awards_pr_id_foreign`
        FOREIGN KEY (`pr_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pr_item_awards_pr_item_id_foreign`
        FOREIGN KEY (`pr_item_id`) REFERENCES `pr_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pr_item_awards_quotation_id_foreign`
        FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pr_item_awards_quotation_item_id_foreign`
        FOREIGN KEY (`quotation_item_id`) REFERENCES `quotation_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `pr_item_awards_supplier_id_foreign`
        FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`),
    CONSTRAINT `pr_item_awards_purchase_order_id_foreign`
        FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
    CONSTRAINT `pr_item_awards_awarded_by_foreign`
        FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2026_09_04_000002_create_shipments_tables
ALTER TABLE `document_sequences`
    MODIFY COLUMN `type` ENUM('PR', 'PO', 'SHP') NOT NULL;

CREATE TABLE `shipments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `shipment_number` VARCHAR(255) NOT NULL,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('draft', 'submitted', 'arrived', 'cancelled') NOT NULL DEFAULT 'draft',
    `shipment_date` DATE NULL,
    `estimated_arrival_date` DATE NULL,
    `actual_arrival_date` DATE NULL,
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `submitted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `shipments_shipment_number_unique` (`shipment_number`),
    KEY `shipments_supplier_id_status_index` (`supplier_id`, `status`),
    KEY `shipments_created_by_foreign` (`created_by`),
    CONSTRAINT `shipments_supplier_id_foreign`
        FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`),
    CONSTRAINT `shipments_created_by_foreign`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shipment_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `shipment_id` BIGINT UNSIGNED NOT NULL,
    `purchase_order_id` BIGINT UNSIGNED NOT NULL,
    `quotation_item_id` BIGINT UNSIGNED NOT NULL,
    `pr_item_award_id` BIGINT UNSIGNED NULL,
    `shipped_quantity` DECIMAL(12,4) NOT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `shipment_items_shipment_id_purchase_order_id_index` (`shipment_id`, `purchase_order_id`),
    KEY `shipment_items_purchase_order_id_quotation_item_id_index` (`purchase_order_id`, `quotation_item_id`),
    UNIQUE KEY `shipment_items_unique_item` (`shipment_id`, `purchase_order_id`, `quotation_item_id`),
    KEY `shipment_items_quotation_item_id_foreign` (`quotation_item_id`),
    KEY `shipment_items_pr_item_award_id_foreign` (`pr_item_award_id`),
    CONSTRAINT `shipment_items_shipment_id_foreign`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `shipment_items_purchase_order_id_foreign`
        FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `shipment_items_quotation_item_id_foreign`
        FOREIGN KEY (`quotation_item_id`) REFERENCES `quotation_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `shipment_items_pr_item_award_id_foreign`
        FOREIGN KEY (`pr_item_award_id`) REFERENCES `pr_item_awards` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shipment_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `shipment_id` BIGINT UNSIGNED NOT NULL,
    `doc_type` ENUM('invoice', 'packing_list', 'bl', 'form_e') NOT NULL,
    `status` ENUM('pending', 'received', 'verified', 'issued', 'processing', 'done') NOT NULL DEFAULT 'pending',
    `document_number` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `shipment_documents_shipment_id_doc_type_index` (`shipment_id`, `doc_type`),
    CONSTRAINT `shipment_documents_shipment_id_foreign`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `qc_inspections`
    ADD COLUMN `shipment_id` BIGINT UNSIGNED NULL AFTER `po_id`,
    ADD KEY `qc_inspections_shipment_id_foreign` (`shipment_id`),
    ADD CONSTRAINT `qc_inspections_shipment_id_foreign`
        FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE SET NULL;

ALTER TABLE `qc_items`
    ADD COLUMN `shipment_item_id` BIGINT UNSIGNED NULL AFTER `pr_item_id`,
    ADD KEY `qc_items_shipment_item_id_foreign` (`shipment_item_id`),
    ADD CONSTRAINT `qc_items_shipment_item_id_foreign`
        FOREIGN KEY (`shipment_item_id`) REFERENCES `shipment_items` (`id`) ON DELETE SET NULL;

-- 2026_09_04_000003_harden_shipment_integrity_constraints
-- Both preflight results must be empty/zero before continuing.
SELECT COUNT(*) AS `non_positive_shipped_quantity_rows`
FROM `shipment_items`
WHERE `shipped_quantity` <= 0;

SELECT `shipment_id`, `doc_type`, COUNT(*) AS `total`
FROM `shipment_documents`
GROUP BY `shipment_id`, `doc_type`
HAVING COUNT(*) > 1;

ALTER TABLE `shipment_documents`
    ADD UNIQUE KEY `shipment_documents_unique_type` (`shipment_id`, `doc_type`);

ALTER TABLE `shipment_items`
    ADD CONSTRAINT `shipment_items_shipped_quantity_positive`
    CHECK (`shipped_quantity` > 0);

-- Keep Laravel's migration repository consistent with the effective schema.
-- All pending migrations are recorded in one batch, matching one migrate run.
SET @adasi_catchup_batch = (
    SELECT COALESCE(MAX(`batch`), 0) + 1
    FROM `migrations`
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_18_000001_create_export_jobs_table', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_18_000001_create_export_jobs_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_20_000001_make_period_month_nullable_for_annual_periods', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_20_000001_make_period_month_nullable_for_annual_periods'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_26_000001_add_cancelled_status_to_export_jobs', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_26_000001_add_cancelled_status_to_export_jobs'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_28_000001_create_auth_known_devices_table', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_28_000001_create_auth_known_devices_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_08_28_000002_add_offer_fields_to_quotation_items_table', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_08_28_000002_add_offer_fields_to_quotation_items_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_03_000001_ensure_quotation_items_price_per_kg_is_nullable', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_03_000001_ensure_quotation_items_price_per_kg_is_nullable'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_03_000002_add_unique_quotation_id_to_po_quotations_table', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_03_000002_add_unique_quotation_id_to_po_quotations_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_03_000003_add_all_unavailable_to_quotation_status_enum', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_03_000003_add_all_unavailable_to_quotation_status_enum'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_04_000001_create_pr_item_awards_table', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_04_000001_create_pr_item_awards_table'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_04_000002_create_shipments_tables', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_04_000002_create_shipments_tables'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_04_000003_harden_shipment_integrity_constraints', @adasi_catchup_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_04_000003_harden_shipment_integrity_constraints'
);

-- Verification output.
SHOW COLUMNS FROM `export_jobs` LIKE 'status';
SHOW CREATE TABLE `auth_known_devices`;
SHOW COLUMNS FROM `quotation_items`;
SHOW INDEX FROM `po_quotations`;
SHOW COLUMNS FROM `quotations` LIKE 'status';
SHOW COLUMNS FROM `document_sequences` LIKE 'type';
SHOW INDEX FROM `pr_item_awards`;
SHOW INDEX FROM `shipments`;
SHOW INDEX FROM `shipment_items`;
SHOW INDEX FROM `shipment_documents`;
SHOW COLUMNS FROM `qc_inspections` LIKE 'shipment_id';
SHOW COLUMNS FROM `qc_items` LIKE 'shipment_item_id';

SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` >= '2026_08_18_000001'
ORDER BY `migration`;

SELECT DATABASE() AS selected_database, CURRENT_TIMESTAMP AS completed_at;
