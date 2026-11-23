-- ==============================================================================
-- SQL equivalent of the 2026-11-19 migrations (MySQL 8.0+ / MariaDB 10.3+)
-- Matching:
--   1. 2026_11_19_000001_add_sales_contract_columns_to_purchase_orders_table
--   2. 2026_11_19_000002_add_invoice_and_reference_columns_to_shipments_table
--   3. 2026_11_19_000003_add_revision_tracking_to_shipment_documents_table
--   4. 2026_11_19_000004_create_preshipment_inspection_tables
--   5. 2026_11_19_000005_extend_preshipment_inspection
--
-- Prerequisites:
--   - Migrations up to 2026_11_17_* already applied (specifically
--     `purchase_orders.acknowledgement_status` and `acknowledgement_notes`).
--   - Take a full database backup before executing: MySQL DDL statements
--     (ALTER TABLE / CREATE TABLE) trigger implicit commits.
--   - Engine: InnoDB, Charset: utf8mb4, Collation: utf8mb4_unicode_ci.
-- ==============================================================================

-- ==============================================================================
-- 1. 2026_11_19_000001_add_sales_contract_columns_to_purchase_orders_table
-- ==============================================================================
ALTER TABLE `purchase_orders`
  ADD COLUMN `sales_contract_status` VARCHAR(32) NULL DEFAULT NULL AFTER `acknowledgement_notes`,
  ADD COLUMN `supplier_sales_contract_attachment_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `sales_contract_status`,
  ADD COLUMN `supplier_sales_contract_uploaded_at` TIMESTAMP NULL DEFAULT NULL AFTER `supplier_sales_contract_attachment_id`,
  ADD COLUMN `purchasing_sales_contract_attachment_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `supplier_sales_contract_uploaded_at`,
  ADD COLUMN `purchasing_sales_contract_uploaded_at` TIMESTAMP NULL DEFAULT NULL AFTER `purchasing_sales_contract_attachment_id`,
  ADD COLUMN `purchasing_sales_contract_uploaded_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `purchasing_sales_contract_uploaded_at`,
  ADD COLUMN `sales_contract_notes` TEXT NULL AFTER `purchasing_sales_contract_uploaded_by`,
  ADD KEY `idx_po_sales_contract_status` (`sales_contract_status`, `status`),
  ADD KEY `fk_po_supplier_sc_attachment` (`supplier_sales_contract_attachment_id`),
  ADD KEY `fk_po_purchasing_sc_attachment` (`purchasing_sales_contract_attachment_id`),
  ADD KEY `fk_po_purchasing_sc_user` (`purchasing_sales_contract_uploaded_by`),
  ADD CONSTRAINT `fk_po_supplier_sc_attachment` FOREIGN KEY (`supplier_sales_contract_attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_po_purchasing_sc_attachment` FOREIGN KEY (`purchasing_sales_contract_attachment_id`) REFERENCES `attachments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_po_purchasing_sc_user` FOREIGN KEY (`purchasing_sales_contract_uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Backfill existing POs that were already acknowledged or advanced in fulfillment
UPDATE `purchase_orders`
SET `sales_contract_status` = 'purchasing_signed'
WHERE `acknowledgement_status` IN ('ACKNOWLEDGED', 'ACCEPTED_CHANGE')
   OR `status` IN ('waiting_qc', 'claim_needed', 'completed', 'cancelled')
   OR EXISTS (
        SELECT 1
        FROM `shipment_items`
        WHERE `shipment_items`.`purchase_order_id` = `purchase_orders`.`id`
      );

-- ==============================================================================
-- 2. 2026_11_19_000002_add_invoice_and_reference_columns_to_shipments_table
-- ==============================================================================
ALTER TABLE `shipments`
  ADD COLUMN `invoice_number` VARCHAR(100) NULL DEFAULT NULL AFTER `shipment_number`,
  ADD COLUMN `supplier_reference` VARCHAR(120) NULL DEFAULT NULL AFTER `invoice_number`,
  ADD KEY `idx_shipments_invoice_number` (`invoice_number`),
  ADD KEY `idx_shipments_supplier_ref` (`supplier_id`, `supplier_reference`);

-- Backfill invoice_number from existing invoice shipment_documents where available
UPDATE `shipments` AS `s`
INNER JOIN `shipment_documents` AS `sd`
  ON `sd`.`shipment_id` = `s`.`id`
 AND `sd`.`doc_type` = 'invoice'
 AND `sd`.`document_number` IS NOT NULL
 AND `sd`.`document_number` <> ''
SET `s`.`invoice_number` = LEFT(`sd`.`document_number`, 100)
WHERE `s`.`invoice_number` IS NULL;

-- ==============================================================================
-- 3. 2026_11_19_000003_add_revision_tracking_to_shipment_documents_table
-- ==============================================================================
ALTER TABLE `shipment_documents`
  ADD COLUMN `revision_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `notes`,
  ADD COLUMN `last_revised_at` TIMESTAMP NULL DEFAULT NULL AFTER `revision_count`,
  ADD COLUMN `revision_note` VARCHAR(500) NULL DEFAULT NULL AFTER `last_revised_at`;

-- Backfill revision_count from existing attachments on ShipmentDocument
UPDATE `shipment_documents` AS `sd`
INNER JOIN (
  SELECT
    `attachable_id`,
    COUNT(*) - 1 AS `rev_count`,
    MAX(`created_at`) AS `latest_at`
  FROM `attachments`
  WHERE `attachable_type` = 'App\\Models\\ShipmentDocument'
  GROUP BY `attachable_id`
  HAVING COUNT(*) > 1
) AS `att` ON `att`.`attachable_id` = `sd`.`id`
SET
  `sd`.`revision_count` = LEAST(`att`.`rev_count`, 65535),
  `sd`.`last_revised_at` = `att`.`latest_at`;

-- E3 Backfill: convert legacy 'awaiting_confirmation' progress updates to 'order_confirmed'
UPDATE `po_item_progress_updates`
SET `status` = 'order_confirmed'
WHERE `status` = 'awaiting_confirmation';

-- ==============================================================================
-- 4. 2026_11_19_000004_create_preshipment_inspection_tables
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `supplier_preshipment_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `exporter_name` VARCHAR(191) NULL DEFAULT NULL,
  `exporter_contact` VARCHAR(150) NULL DEFAULT NULL,
  `exporter_address` TEXT NULL,
  `exporter_city` VARCHAR(120) NULL DEFAULT NULL,
  `exporter_country` VARCHAR(120) NULL DEFAULT NULL,
  `exporter_zip_code` VARCHAR(40) NULL DEFAULT NULL,
  `exporter_telephone` VARCHAR(60) NULL DEFAULT NULL,
  `exporter_mobile` VARCHAR(60) NULL DEFAULT NULL,
  `exporter_fax` VARCHAR(60) NULL DEFAULT NULL,
  `exporter_email` VARCHAR(191) NULL DEFAULT NULL,
  `exporter_port_of_loading` VARCHAR(150) NULL DEFAULT NULL,
  `supplier_name` VARCHAR(191) NULL DEFAULT NULL,
  `supplier_contact` VARCHAR(150) NULL DEFAULT NULL,
  `supplier_address` TEXT NULL,
  `supplier_country` VARCHAR(120) NULL DEFAULT NULL,
  `supplier_city` VARCHAR(120) NULL DEFAULT NULL,
  `supplier_telephone` VARCHAR(60) NULL DEFAULT NULL,
  `supplier_mobile` VARCHAR(60) NULL DEFAULT NULL,
  `supplier_fax` VARCHAR(60) NULL DEFAULT NULL,
  `supplier_email` VARCHAR(191) NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_preshipment_profiles_supplier_id_unique` (`supplier_id`),
  KEY `fk_preshipment_profile_updater` (`updated_by`),
  CONSTRAINT `fk_preshipment_profile_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_preshipment_profile_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipment_preshipment_inspections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shipment_id` BIGINT UNSIGNED NOT NULL,
  `exporter_name` VARCHAR(191) NULL DEFAULT NULL,
  `exporter_contact` VARCHAR(150) NULL DEFAULT NULL,
  `exporter_address` TEXT NULL,
  `exporter_city` VARCHAR(120) NULL DEFAULT NULL,
  `exporter_country` VARCHAR(120) NULL DEFAULT NULL,
  `exporter_zip_code` VARCHAR(40) NULL DEFAULT NULL,
  `exporter_telephone` VARCHAR(60) NULL DEFAULT NULL,
  `exporter_mobile` VARCHAR(60) NULL DEFAULT NULL,
  `exporter_fax` VARCHAR(60) NULL DEFAULT NULL,
  `exporter_email` VARCHAR(191) NULL DEFAULT NULL,
  `exporter_port_of_loading` VARCHAR(150) NULL DEFAULT NULL,
  `supplier_name` VARCHAR(191) NULL DEFAULT NULL,
  `supplier_contact` VARCHAR(150) NULL DEFAULT NULL,
  `supplier_address` TEXT NULL,
  `supplier_country` VARCHAR(120) NULL DEFAULT NULL,
  `supplier_city` VARCHAR(120) NULL DEFAULT NULL,
  `supplier_telephone` VARCHAR(60) NULL DEFAULT NULL,
  `supplier_mobile` VARCHAR(60) NULL DEFAULT NULL,
  `supplier_fax` VARCHAR(60) NULL DEFAULT NULL,
  `supplier_email` VARCHAR(191) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_preshipment_inspections_shipment_id_unique` (`shipment_id`),
  CONSTRAINT `fk_preshipment_inspection_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- 5. 2026_11_19_000005_extend_preshipment_inspection
-- ==============================================================================
CREATE TABLE IF NOT EXISTS `supplier_goods_locations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `contact` VARCHAR(150) NULL DEFAULT NULL,
  `address` TEXT NOT NULL,
  `country` VARCHAR(120) NOT NULL,
  `city` VARCHAR(120) NOT NULL,
  `telephone` VARCHAR(60) NULL DEFAULT NULL,
  `mobile` VARCHAR(60) NULL DEFAULT NULL,
  `fax` VARCHAR(60) NULL DEFAULT NULL,
  `email` VARCHAR(191) NULL DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_goods_loc_supplier_active` (`supplier_id`, `deleted_at`, `is_default`),
  KEY `fk_goods_loc_creator` (`created_by`),
  KEY `fk_goods_loc_updater` (`updated_by`),
  CONSTRAINT `fk_goods_loc_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_goods_loc_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_goods_loc_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `shipment_preshipment_inspections`
  ADD COLUMN `goods_location_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `shipment_id`,
  ADD COLUMN `supplier_same_as_exporter` TINYINT(1) NOT NULL DEFAULT 0 AFTER `supplier_email`,
  ADD COLUMN `revision_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `supplier_same_as_exporter`,
  ADD COLUMN `last_revised_at` TIMESTAMP NULL DEFAULT NULL AFTER `revision_count`,
  ADD COLUMN `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `last_revised_at`,
  ADD KEY `idx_psi_goods_location` (`goods_location_id`),
  ADD KEY `fk_psi_updater` (`updated_by`),
  ADD CONSTRAINT `fk_psi_goods_location` FOREIGN KEY (`goods_location_id`) REFERENCES `supplier_goods_locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_psi_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Idempotent backfill: move legacy supplier_* fields on supplier_preshipment_profiles
-- into an initial default supplier_goods_locations row when none exists for that supplier.
INSERT INTO `supplier_goods_locations` (
  `supplier_id`,
  `name`,
  `contact`,
  `address`,
  `country`,
  `city`,
  `telephone`,
  `mobile`,
  `fax`,
  `email`,
  `is_default`,
  `created_by`,
  `updated_by`,
  `created_at`,
  `updated_at`
)
SELECT
  `p`.`supplier_id`,
  LEFT(
    COALESCE(
      NULLIF(TRIM(`p`.`supplier_name`), ''),
      NULLIF(TRIM(`p`.`exporter_name`), ''),
      'Main location'
    ),
    191
  ) AS `name`,
  NULLIF(TRIM(`p`.`supplier_contact`), '') AS `contact`,
  COALESCE(
    NULLIF(TRIM(`p`.`supplier_address`), ''),
    NULLIF(TRIM(`p`.`exporter_address`), ''),
    '-'
  ) AS `address`,
  LEFT(
    COALESCE(
      NULLIF(TRIM(`p`.`supplier_country`), ''),
      NULLIF(TRIM(`p`.`exporter_country`), ''),
      ''
    ),
    120
  ) AS `country`,
  LEFT(
    COALESCE(
      NULLIF(TRIM(`p`.`supplier_city`), ''),
      NULLIF(TRIM(`p`.`exporter_city`), ''),
      ''
    ),
    120
  ) AS `city`,
  NULLIF(TRIM(`p`.`supplier_telephone`), '') AS `telephone`,
  NULLIF(TRIM(`p`.`supplier_mobile`), '') AS `mobile`,
  NULLIF(TRIM(`p`.`supplier_fax`), '') AS `fax`,
  NULLIF(TRIM(`p`.`supplier_email`), '') AS `email`,
  1 AS `is_default`,
  COALESCE(`p`.`updated_by`, `p`.`supplier_id`) AS `created_by`,
  COALESCE(`p`.`updated_by`, `p`.`supplier_id`) AS `updated_by`,
  COALESCE(`p`.`created_at`, UTC_TIMESTAMP()) AS `created_at`,
  COALESCE(`p`.`updated_at`, UTC_TIMESTAMP()) AS `updated_at`
FROM `supplier_preshipment_profiles` AS `p`
WHERE (
    COALESCE(TRIM(`p`.`supplier_name`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_contact`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_address`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_country`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_city`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_telephone`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_mobile`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_fax`), '') <> ''
    OR COALESCE(TRIM(`p`.`supplier_email`), '') <> ''
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `supplier_goods_locations` AS `sgl`
    WHERE `sgl`.`supplier_id` = `p`.`supplier_id`
  );

-- ==============================================================================
-- Register in Laravel's migrations table (skip if you run `php artisan migrate`)
-- ==============================================================================
SET @batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`)
SELECT `m`.`migration`, @batch
FROM (
  SELECT '2026_11_19_000001_add_sales_contract_columns_to_purchase_orders_table' AS `migration`
  UNION ALL SELECT '2026_11_19_000002_add_invoice_and_reference_columns_to_shipments_table'
  UNION ALL SELECT '2026_11_19_000003_add_revision_tracking_to_shipment_documents_table'
  UNION ALL SELECT '2026_11_19_000004_create_preshipment_inspection_tables'
  UNION ALL SELECT '2026_11_19_000005_extend_preshipment_inspection'
) AS `m`
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations` AS `existing` WHERE `existing`.`migration` = `m`.`migration`
);

-- ==============================================================================
-- ROLLBACK (manual, reverse order)
-- Migration 000005 refuses rollback if any of these return > 0:
--   SELECT COUNT(*) FROM `supplier_goods_locations`;
--   SELECT COUNT(*) FROM `shipment_preshipment_inspections`
--    WHERE `goods_location_id` IS NOT NULL
--       OR `supplier_same_as_exporter` = 1
--       OR `revision_count` > 0
--       OR `last_revised_at` IS NOT NULL
--       OR `updated_by` IS NOT NULL;
-- ==============================================================================
/*
-- 5. Rollback 2026_11_19_000005
ALTER TABLE `shipment_preshipment_inspections`
  DROP FOREIGN KEY `fk_psi_goods_location`,
  DROP FOREIGN KEY `fk_psi_updater`,
  DROP KEY `idx_psi_goods_location`,
  DROP COLUMN `goods_location_id`,
  DROP COLUMN `supplier_same_as_exporter`,
  DROP COLUMN `revision_count`,
  DROP COLUMN `last_revised_at`,
  DROP COLUMN `updated_by`;
DROP TABLE IF EXISTS `supplier_goods_locations`;

-- 4. Rollback 2026_11_19_000004
DROP TABLE IF EXISTS `shipment_preshipment_inspections`;
DROP TABLE IF EXISTS `supplier_preshipment_profiles`;

-- 3. Rollback 2026_11_19_000003
ALTER TABLE `shipment_documents`
  DROP COLUMN `revision_count`,
  DROP COLUMN `last_revised_at`,
  DROP COLUMN `revision_note`;

-- 2. Rollback 2026_11_19_000002
ALTER TABLE `shipments`
  DROP KEY `idx_shipments_invoice_number`,
  DROP KEY `idx_shipments_supplier_ref`,
  DROP COLUMN `invoice_number`,
  DROP COLUMN `supplier_reference`;

-- 1. Rollback 2026_11_19_000001
ALTER TABLE `purchase_orders`
  DROP FOREIGN KEY `fk_po_supplier_sc_attachment`,
  DROP FOREIGN KEY `fk_po_purchasing_sc_attachment`,
  DROP FOREIGN KEY `fk_po_purchasing_sc_user`,
  DROP KEY `idx_po_sales_contract_status`,
  DROP COLUMN `sales_contract_status`,
  DROP COLUMN `supplier_sales_contract_attachment_id`,
  DROP COLUMN `supplier_sales_contract_uploaded_at`,
  DROP COLUMN `purchasing_sales_contract_attachment_id`,
  DROP COLUMN `purchasing_sales_contract_uploaded_at`,
  DROP COLUMN `purchasing_sales_contract_uploaded_by`,
  DROP COLUMN `sales_contract_notes`;

DELETE FROM `migrations` WHERE `migration` IN (
  '2026_11_19_000005_extend_preshipment_inspection',
  '2026_11_19_000004_create_preshipment_inspection_tables',
  '2026_11_19_000003_add_revision_tracking_to_shipment_documents_table',
  '2026_11_19_000002_add_invoice_and_reference_columns_to_shipments_table',
  '2026_11_19_000001_add_sales_contract_columns_to_purchase_orders_table'
);
*/
