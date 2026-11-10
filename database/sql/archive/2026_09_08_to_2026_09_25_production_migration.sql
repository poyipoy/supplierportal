-- ==============================================================================
-- ADASI Supplier Portal - Production Database Migration Script
-- ==============================================================================
-- Scope:
--   From: 2026_09_08_000002_create_local_invoice_domain
--   To:   2026_09_25_000001_add_ready_to_pay_at_status_index_to_local_invoices_table
--
-- Excluded by design:
--   2026_09_08_000002_create_po_item_progress_updates_table (Explicitly omitted)
--
-- Total Source Migrations: 25 files
--
-- Instructions:
--   1. Always take a complete database backup before executing this script.
--   2. Target Database Engine: MySQL 8.0+ / MariaDB 10.5+ (InnoDB)
--   3. Run this script once on the target production database.
--   4. This script synchronizes the `migrations` table ledger so that Laravel's
--      `php artisan migrate:status` recognizes all 25 migrations as applied.
-- ==============================================================================

SELECT DATABASE() AS `selected_database`, CURRENT_TIMESTAMP AS `started_at`;

SET @OLD_UNIQUE_CHECKS = @@UNIQUE_CHECKS, UNIQUE_CHECKS = 0;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE, SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;


-- ==============================================================================
-- 1. Migration: 2026_09_08_000002_create_local_invoice_domain
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `local_invoice_sequences` (
    `year` SMALLINT UNSIGNED NOT NULL,
    `last_number` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `submission_number` VARCHAR(255) NOT NULL,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `invoice_number` VARCHAR(100) NOT NULL,
    `invoice_date` DATE NOT NULL,
    `po_number` VARCHAR(100) NOT NULL,
    `currency` CHAR(3) NOT NULL DEFAULT 'IDR',
    `invoice_amount` DECIMAL(20, 2) NOT NULL,
    `tax_amount` DECIMAL(20, 2) NOT NULL,
    `payment_term_days_snapshot` SMALLINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('WAITING_PHYSICAL_DOCUMENT', 'UNDER_REVIEW', 'NEED_REVISION', 'REJECTED', 'APPROVED', 'PAYMENT_SCHEDULED', 'COMPLETED') NOT NULL,
    `submitted_at` TIMESTAMP NOT NULL,
    `physical_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `review_started_at` TIMESTAMP NULL DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `due_date` DATE NULL DEFAULT NULL,
    `payment_scheduled_at` TIMESTAMP NULL DEFAULT NULL,
    `scheduled_payment_date` DATE NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_invoices_submission_number_unique` (`submission_number`),
    UNIQUE KEY `local_invoices_supplier_id_invoice_number_unique` (`supplier_id`, `invoice_number`),
    KEY `local_invoices_supplier_id_status_index` (`supplier_id`, `status`),
    KEY `local_invoices_status_submitted_at_index` (`status`, `submitted_at`),
    KEY `local_invoices_po_number_index` (`po_number`),
    KEY `local_invoices_due_date_index` (`due_date`),
    CONSTRAINT `local_invoices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_revisions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL,
    `invoice_number` VARCHAR(100) NOT NULL,
    `invoice_date` DATE NOT NULL,
    `po_number` VARCHAR(100) NOT NULL,
    `invoice_amount` DECIMAL(20, 2) NOT NULL,
    `tax_amount` DECIMAL(20, 2) NOT NULL,
    `reason` TEXT NULL DEFAULT NULL,
    `requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `requested_at` TIMESTAMP NULL DEFAULT NULL,
    `resubmitted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_revision_unique` (`local_invoice_id`, `revision_number`),
    UNIQUE KEY `local_revision_parent_unique` (`id`, `local_invoice_id`),
    KEY `local_invoice_revisions_requested_by_foreign` (`requested_by`),
    CONSTRAINT `local_invoice_revisions_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_revisions_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `local_invoice_revision_id` BIGINT UNSIGNED NOT NULL,
    `document_type` ENUM('invoice', 'tax_invoice', 'supporting') NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL,
    `uploaded_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_invoice_documents_file_path_unique` (`file_path`),
    UNIQUE KEY `local_document_type_unique` (`local_invoice_revision_id`, `document_type`),
    KEY `local_invoice_documents_local_invoice_id_foreign` (`local_invoice_id`),
    KEY `local_invoice_documents_uploaded_by_foreign` (`uploaded_by`),
    CONSTRAINT `local_invoice_documents_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_document_revision_fk` FOREIGN KEY (`local_invoice_revision_id`, `local_invoice_id`) REFERENCES `local_invoice_revisions` (`id`, `local_invoice_id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_receipts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `receipt_number` VARCHAR(255) NOT NULL,
    `issued_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_invoice_receipts_local_invoice_id_unique` (`local_invoice_id`),
    UNIQUE KEY `local_invoice_receipts_receipt_number_unique` (`receipt_number`),
    CONSTRAINT `local_invoice_receipts_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_status_histories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `from_status` VARCHAR(255) NULL DEFAULT NULL,
    `to_status` VARCHAR(255) NOT NULL,
    `actor_id` BIGINT UNSIGNED NOT NULL,
    `event` VARCHAR(255) NOT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    KEY `local_invoice_status_histories_local_invoice_id_foreign` (`local_invoice_id`),
    KEY `local_invoice_status_histories_actor_id_foreign` (`actor_id`),
    CONSTRAINT `local_invoice_status_histories_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_status_histories_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_physical_verifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL,
    `status` ENUM('matched', 'invalidated') NOT NULL,
    `verified_by` BIGINT UNSIGNED NOT NULL,
    `verified_at` TIMESTAMP NOT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_physical_revision_unique` (`local_invoice_id`, `revision_number`, `status`),
    KEY `local_invoice_physical_verifications_verified_by_foreign` (`verified_by`),
    CONSTRAINT `local_invoice_physical_verifications_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_physical_verifications_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 2. Migration: 2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga
-- ==============================================================================

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin','purchasing','supplier','qc','accounting','finance','ga') NOT NULL DEFAULT 'supplier';

-- Migrate existing accounting users to finance
UPDATE `users`
SET `role` = 'finance'
WHERE `role` = 'accounting';

-- Audit log entry if auth_audit_logs exists
INSERT INTO `auth_audit_logs` (`user_id`, `event`, `metadata`, `created_at`)
SELECT `id`, 'role_migrated_accounting_to_finance', '{"from":"accounting","to":"finance","migration":"2026_09_11_000001"}', CURRENT_TIMESTAMP
FROM `users`
WHERE `role` = 'finance'
  AND NOT EXISTS (
      SELECT 1 FROM `auth_audit_logs`
      WHERE `auth_audit_logs`.`user_id` = `users`.`id`
        AND `auth_audit_logs`.`event` = 'role_migrated_accounting_to_finance'
  );


-- ==============================================================================
-- 3. Migration: 2026_09_11_000002_create_vendor_master_v2_tables
-- ==============================================================================

ALTER TABLE `suppliers`
    ADD COLUMN `vendor_category` VARCHAR(50) NULL DEFAULT NULL AFTER `category`,
    ADD COLUMN `is_pkp` TINYINT(1) NOT NULL DEFAULT 0 AFTER `vendor_category`,
    ADD COLUMN `pic_name` VARCHAR(100) NULL DEFAULT NULL AFTER `is_pkp`,
    ADD COLUMN `pic_email` VARCHAR(100) NULL DEFAULT NULL AFTER `pic_name`,
    ADD COLUMN `pic_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `pic_email`;

CREATE TABLE IF NOT EXISTS `supplier_bank_accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `bank_name` VARCHAR(100) NOT NULL,
    `account_number` VARCHAR(50) NOT NULL,
    `account_holder_name` VARCHAR(150) NOT NULL,
    `status` ENUM('PENDING', 'VERIFIED', 'REJECTED', 'INACTIVE') NOT NULL DEFAULT 'PENDING',
    `verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `activated_at` TIMESTAMP NULL DEFAULT NULL,
    `deactivated_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `supplier_bank_accounts_supplier_id_status_index` (`supplier_id`, `status`),
    KEY `supplier_bank_accounts_verified_by_foreign` (`verified_by`),
    CONSTRAINT `supplier_bank_accounts_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_bank_accounts_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_change_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `change_type` VARCHAR(50) NOT NULL DEFAULT 'profile',
    `current_data_snapshot` JSON NULL DEFAULT NULL,
    `proposed_data` JSON NOT NULL,
    `status` ENUM('PENDING', 'APPROVED', 'REJECTED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
    `requested_by` BIGINT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    `review_notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `supplier_change_requests_supplier_id_status_index` (`supplier_id`, `status`),
    KEY `supplier_change_requests_requested_by_foreign` (`requested_by`),
    KEY `supplier_change_requests_reviewed_by_foreign` (`reviewed_by`),
    CONSTRAINT `supplier_change_requests_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_change_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_change_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_master_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `document_type` ENUM('NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'OTHER') NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL,
    `uploaded_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `supplier_master_documents_file_path_unique` (`file_path`),
    KEY `supplier_master_documents_supplier_id_document_type_index` (`supplier_id`, `document_type`),
    KEY `supplier_master_documents_uploaded_by_foreign` (`uploaded_by`),
    CONSTRAINT `supplier_master_documents_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_master_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 4. Migration: 2026_09_11_000003_add_v2_fields_to_local_invoices_table
-- ==============================================================================

ALTER TABLE `local_invoices`
    ADD COLUMN `po_source` ENUM('INTERNAL', 'MANUAL') NOT NULL DEFAULT 'INTERNAL' AFTER `invoice_date`,
    ADD COLUMN `internal_po_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `po_number`,
    ADD COLUMN `manual_po_number` VARCHAR(100) NULL DEFAULT NULL AFTER `internal_po_reference`,
    ADD COLUMN `internal_gr_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `manual_po_number`,
    ADD COLUMN `manual_gr_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `internal_gr_reference`,
    ADD COLUMN `po_value_snapshot` DECIMAL(20, 2) NULL DEFAULT NULL AFTER `manual_gr_reference`,
    ADD COLUMN `po_invoiced_snapshot` DECIMAL(20, 2) NULL DEFAULT NULL AFTER `po_value_snapshot`,
    ADD COLUMN `po_remaining_snapshot` DECIMAL(20, 2) NULL DEFAULT NULL AFTER `po_invoiced_snapshot`,
    ADD COLUMN `has_po_discrepancy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `po_remaining_snapshot`,
    ADD COLUMN `ppn_scheme` VARCHAR(20) NULL DEFAULT NULL AFTER `tax_amount`,
    ADD COLUMN `submitted_ppn_amount` DECIMAL(20, 2) NULL DEFAULT NULL AFTER `ppn_scheme`,
    ADD COLUMN `scheduled_physical_delivery_date` DATE NULL DEFAULT NULL AFTER `submitted_ppn_amount`,
    ADD COLUMN `missed_delivery_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `scheduled_physical_delivery_date`,
    ADD COLUMN `rescheduled_at` TIMESTAMP NULL DEFAULT NULL AFTER `missed_delivery_count`,
    ADD COLUMN `rescheduled_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `rescheduled_at`,
    ADD COLUMN `cashier_received_at` TIMESTAMP NULL DEFAULT NULL AFTER `physical_verified_at`,
    ADD COLUMN `cashier_received_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `cashier_received_at`,
    ADD COLUMN `ready_to_pay_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_at`,
    ADD COLUMN `paid_at` TIMESTAMP NULL DEFAULT NULL AFTER `completed_at`,
    ADD COLUMN `expired_at` TIMESTAMP NULL DEFAULT NULL AFTER `paid_at`,
    ADD CONSTRAINT `local_invoices_rescheduled_by_foreign` FOREIGN KEY (`rescheduled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `local_invoices_cashier_received_by_foreign` FOREIGN KEY (`cashier_received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `local_invoices`
    MODIFY COLUMN `payment_term_days_snapshot` SMALLINT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `local_invoice_revisions`
    ADD COLUMN `po_source` ENUM('INTERNAL', 'MANUAL') NOT NULL DEFAULT 'INTERNAL' AFTER `invoice_date`,
    ADD COLUMN `internal_po_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `po_number`,
    ADD COLUMN `manual_po_number` VARCHAR(100) NULL DEFAULT NULL AFTER `internal_po_reference`,
    ADD COLUMN `internal_gr_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `manual_po_number`,
    ADD COLUMN `manual_gr_reference` VARCHAR(100) NULL DEFAULT NULL AFTER `internal_gr_reference`,
    ADD COLUMN `ppn_scheme` VARCHAR(20) NULL DEFAULT NULL AFTER `tax_amount`,
    ADD COLUMN `submitted_ppn_amount` DECIMAL(20, 2) NULL DEFAULT NULL AFTER `ppn_scheme`,
    ADD COLUMN `has_po_discrepancy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `submitted_ppn_amount`;

UPDATE `local_invoices` SET `status` = 'UNDER_VERIFICATION' WHERE `status` = 'UNDER_REVIEW';
UPDATE `local_invoices` SET `status` = 'READY_TO_PAY' WHERE `status` IN ('APPROVED', 'PAYMENT_SCHEDULED');
UPDATE `local_invoices` SET `status` = 'PAID' WHERE `status` = 'COMPLETED';

ALTER TABLE `local_invoices`
    MODIFY COLUMN `status` ENUM('WAITING_PHYSICAL_DOCUMENT', 'UNDER_VERIFICATION', 'UNDER_REVIEW', 'NEED_REVISION', 'READY_TO_PAY', 'APPROVED', 'PAYMENT_SCHEDULED', 'PAID', 'COMPLETED', 'EXPIRED', 'REJECTED') NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT';

ALTER TABLE `local_invoice_documents`
    MODIFY COLUMN `document_type` ENUM('invoice', 'tax_invoice', 'delivery_note', 'supporting') NOT NULL;


-- ==============================================================================
-- 5. Migration: 2026_09_11_000004_create_local_invoice_verification_tables
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `local_invoice_verifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `invoice_check` ENUM('OK', 'NOT_OK') NOT NULL DEFAULT 'OK',
    `invoice_notes` TEXT NULL DEFAULT NULL,
    `tax_invoice_check` ENUM('OK', 'NOT_OK', 'NOT_APPLICABLE') NOT NULL DEFAULT 'OK',
    `tax_invoice_notes` TEXT NULL DEFAULT NULL,
    `po_check` ENUM('OK', 'NOT_OK') NOT NULL DEFAULT 'OK',
    `po_notes` TEXT NULL DEFAULT NULL,
    `delivery_note_check` ENUM('OK', 'NOT_OK', 'NOT_APPLICABLE') NOT NULL DEFAULT 'OK',
    `delivery_note_notes` TEXT NULL DEFAULT NULL,
    `gr_check` ENUM('OK', 'NOT_OK') NOT NULL DEFAULT 'OK',
    `gr_notes` TEXT NULL DEFAULT NULL,
    `ppn_status` ENUM('SESUAI', 'TIDAK_SESUAI') NOT NULL DEFAULT 'SESUAI',
    `submitted_ppn` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `verified_ppn` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `pph_23_applicable` TINYINT(1) NOT NULL DEFAULT 0,
    `pph_23_base` DECIMAL(20, 2) NULL DEFAULT NULL,
    `pph_23_rate` DECIMAL(5, 2) NULL DEFAULT NULL,
    `pph_23_amount` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `pph_4_2_applicable` TINYINT(1) NOT NULL DEFAULT 0,
    `pph_4_2_amount` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `pph_21_applicable` TINYINT(1) NOT NULL DEFAULT 0,
    `pph_21_amount` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `tax_notes` TEXT NULL DEFAULT NULL,
    `is_section_a_passed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_section_b_passed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `liv_invoice_rev_unique` (`local_invoice_id`, `revision_number`),
    KEY `local_invoice_verifications_verified_by_foreign` (`verified_by`),
    CONSTRAINT `local_invoice_verifications_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE CASCADE,
    CONSTRAINT `local_invoice_verifications_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 6. Migration: 2026_09_11_000005_create_employee_and_ga_claim_tables
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `employees` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `department` VARCHAR(100) NOT NULL,
    `bank_name` VARCHAR(100) NOT NULL,
    `account_number` VARCHAR(50) NOT NULL,
    `account_holder_name` VARCHAR(150) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `employees_is_active_department_index` (`is_active`, `department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ga_claims` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `claim_number` VARCHAR(100) NOT NULL,
    `employee_id` BIGINT UNSIGNED NOT NULL,
    `claim_type` ENUM('Entertain Sales', 'UPD Sales', 'UPD GA', 'Reimburse/Claim') NOT NULL,
    `claim_date` DATE NOT NULL,
    `amount` DECIMAL(20, 2) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `status` ENUM('SUBMITTED', 'BASIC_VERIFIED', 'UNDER_VERIFICATION', 'NEED_REVISION', 'READY_TO_PAY', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'SUBMITTED',
    `revision_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `revision_reason` TEXT NULL DEFAULT NULL,
    `submitted_by` BIGINT UNSIGNED NOT NULL,
    `submitted_at` TIMESTAMP NOT NULL,
    `basic_verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `basic_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `finance_verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `finance_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `ready_to_pay_at` TIMESTAMP NULL DEFAULT NULL,
    `paid_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ga_claims_claim_number_unique` (`claim_number`),
    KEY `ga_claims_status_claim_date_index` (`status`, `claim_date`),
    KEY `ga_claims_employee_id_status_index` (`employee_id`, `status`),
    KEY `ga_claims_submitted_by_foreign` (`submitted_by`),
    KEY `ga_claims_basic_verified_by_foreign` (`basic_verified_by`),
    KEY `ga_claims_finance_verified_by_foreign` (`finance_verified_by`),
    CONSTRAINT `ga_claims_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `ga_claims_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `ga_claims_basic_verified_by_foreign` FOREIGN KEY (`basic_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `ga_claims_finance_verified_by_foreign` FOREIGN KEY (`finance_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ga_claim_receipts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ga_claim_id` BIGINT UNSIGNED NOT NULL,
    `receipt_number` VARCHAR(100) NOT NULL,
    `issued_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ga_claim_receipts_ga_claim_id_unique` (`ga_claim_id`),
    UNIQUE KEY `ga_claim_receipts_receipt_number_unique` (`receipt_number`),
    CONSTRAINT `ga_claim_receipts_ga_claim_id_foreign` FOREIGN KEY (`ga_claim_id`) REFERENCES `ga_claims` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ga_claim_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ga_claim_id` BIGINT UNSIGNED NOT NULL,
    `revision_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `document_type` VARCHAR(50) NOT NULL DEFAULT 'supporting',
    `file_path` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL,
    `uploaded_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ga_claim_documents_file_path_unique` (`file_path`),
    KEY `ga_claim_documents_ga_claim_id_revision_number_index` (`ga_claim_id`, `revision_number`),
    KEY `ga_claim_documents_uploaded_by_foreign` (`uploaded_by`),
    CONSTRAINT `ga_claim_documents_ga_claim_id_foreign` FOREIGN KEY (`ga_claim_id`) REFERENCES `ga_claims` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ga_claim_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ga_claim_status_histories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ga_claim_id` BIGINT UNSIGNED NOT NULL,
    `from_status` VARCHAR(255) NULL DEFAULT NULL,
    `to_status` VARCHAR(255) NOT NULL,
    `actor_id` BIGINT UNSIGNED NOT NULL,
    `event` VARCHAR(255) NOT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ga_claim_status_histories_ga_claim_id_foreign` (`ga_claim_id`),
    KEY `ga_claim_status_histories_actor_id_foreign` (`actor_id`),
    CONSTRAINT `ga_claim_status_histories_ga_claim_id_foreign` FOREIGN KEY (`ga_claim_id`) REFERENCES `ga_claims` (`id`) ON DELETE CASCADE,
    CONSTRAINT `ga_claim_status_histories_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 7. Migration: 2026_09_11_000006_create_unified_payment_batches_tables
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `payment_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_number` VARCHAR(100) NOT NULL,
    `batch_type` ENUM('SUPPLIER', 'GA') NOT NULL,
    `status` ENUM('DRAFT', 'FINALIZED', 'PARTIALLY_PAID', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    `total_subtotal` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `total_bank_fee` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `total_net_amount` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `finalized_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `finalized_at` TIMESTAMP NULL DEFAULT NULL,
    `paid_at` TIMESTAMP NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `payment_batches_batch_number_unique` (`batch_number`),
    KEY `payment_batches_batch_type_status_index` (`batch_type`, `status`),
    KEY `payment_batches_created_by_foreign` (`created_by`),
    KEY `payment_batches_finalized_by_foreign` (`finalized_by`),
    CONSTRAINT `payment_batches_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `payment_batches_finalized_by_foreign` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_groups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_batch_id` BIGINT UNSIGNED NOT NULL,
    `payee_type` VARCHAR(50) NOT NULL,
    `payee_id` BIGINT UNSIGNED NOT NULL,
    `payee_name` VARCHAR(150) NOT NULL,
    `bank_name` VARCHAR(100) NOT NULL,
    `account_number` VARCHAR(50) NOT NULL,
    `account_holder_name` VARCHAR(150) NOT NULL,
    `subtotal_amount` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `bank_fee` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `net_payment_amount` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `fee_override_reason` TEXT NULL DEFAULT NULL,
    `status` ENUM('UNPAID', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'UNPAID',
    `transfer_reference` VARCHAR(100) NULL DEFAULT NULL,
    `transfer_date` DATE NULL DEFAULT NULL,
    `payment_notes` TEXT NULL DEFAULT NULL,
    `voucher_number` VARCHAR(100) NULL DEFAULT NULL,
    `voucher_date` DATE NULL DEFAULT NULL,
    `paid_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `paid_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `payment_groups_payment_batch_id_status_index` (`payment_batch_id`, `status`),
    KEY `payment_groups_payee_type_payee_id_index` (`payee_type`, `payee_id`),
    KEY `payment_groups_paid_by_foreign` (`paid_by`),
    CONSTRAINT `payment_groups_payment_batch_id_foreign` FOREIGN KEY (`payment_batch_id`) REFERENCES `payment_batches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `payment_groups_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_group_id` BIGINT UNSIGNED NOT NULL,
    `payable_type` VARCHAR(50) NOT NULL,
    `payable_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(20, 2) NOT NULL,
    `status` ENUM('ACTIVE', 'REMOVED') NOT NULL DEFAULT 'ACTIVE',
    `removed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `removed_at` TIMESTAMP NULL DEFAULT NULL,
    `removal_reason` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `payment_items_payable_type_payable_id_status_index` (`payable_type`, `payable_id`, `status`),
    KEY `payment_items_payment_group_id_status_index` (`payment_group_id`, `status`),
    KEY `payment_items_removed_by_foreign` (`removed_by`),
    CONSTRAINT `payment_items_payment_group_id_foreign` FOREIGN KEY (`payment_group_id`) REFERENCES `payment_groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `payment_items_removed_by_foreign` FOREIGN KEY (`removed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 8. Migration: 2026_09_14_000001_harden_local_invoice_and_payment_invariants
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `local_purchase_orders` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `po_number` VARCHAR(100) NOT NULL,
    `po_date` DATE NOT NULL,
    `total_amount` DECIMAL(20, 2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'IDR',
    `description` TEXT NULL DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'APPROVED',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_purchase_orders_po_number_unique` (`po_number`),
    KEY `local_purchase_orders_supplier_id_status_index` (`supplier_id`, `status`),
    CONSTRAINT `local_purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_goods_receipts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_purchase_order_id` BIGINT UNSIGNED NOT NULL,
    `gr_number` VARCHAR(100) NOT NULL,
    `gr_date` DATE NOT NULL,
    `received_amount` DECIMAL(20, 2) NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_goods_receipts_gr_number_unique` (`gr_number`),
    KEY `local_goods_receipts_local_purchase_order_id_gr_date_index` (`local_purchase_order_id`, `gr_date`),
    CONSTRAINT `local_goods_receipts_local_purchase_order_id_foreign` FOREIGN KEY (`local_purchase_order_id`) REFERENCES `local_purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `payment_items`
    ADD INDEX `idx_payment_items_reservation` (`payable_type`, `payable_id`, `status`);


-- ==============================================================================
-- 9. Migration: 2026_09_14_000002_add_tax_invoice_number_to_local_invoices
-- ==============================================================================

ALTER TABLE `local_invoices`
    ADD COLUMN `tax_invoice_number` VARCHAR(30) NULL DEFAULT NULL AFTER `tax_amount`,
    ADD INDEX `local_invoices_supplier_id_tax_invoice_number_index` (`supplier_id`, `tax_invoice_number`);

ALTER TABLE `local_invoice_revisions`
    ADD COLUMN `tax_invoice_number` VARCHAR(30) NULL DEFAULT NULL AFTER `tax_amount`;


-- ==============================================================================
-- 10. Migration: 2026_09_14_000003_add_cancelled_status_to_payment_groups
-- ==============================================================================

ALTER TABLE `payment_groups`
    MODIFY COLUMN `status` ENUM('UNPAID', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'UNPAID';


-- ==============================================================================
-- 11. Migration: 2026_09_15_000001_implement_local_supplier_whole_gr_settlement
-- ==============================================================================

ALTER TABLE `local_purchase_orders`
    ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'MANUAL' AFTER `status`,
    ADD COLUMN `created_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `source`,
    ADD COLUMN `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `created_by`,
    ADD INDEX `local_po_date_idx` (`po_date`),
    ADD CONSTRAINT `local_purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `local_purchase_orders_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

UPDATE `local_purchase_orders` SET `status` = 'OPEN' WHERE `status` = 'APPROVED';

ALTER TABLE `local_invoices`
    ADD COLUMN `local_purchase_order_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `supplier_id`,
    ADD CONSTRAINT `local_invoices_local_purchase_order_id_foreign` FOREIGN KEY (`local_purchase_order_id`) REFERENCES `local_purchase_orders` (`id`) ON DELETE RESTRICT;

ALTER TABLE `local_goods_receipts`
    ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE' AFTER `notes`,
    ADD COLUMN `current_invoice_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `status`,
    ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'MANUAL' AFTER `current_invoice_id`,
    ADD COLUMN `created_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `source`,
    ADD COLUMN `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `created_by`,
    ADD INDEX `local_gr_po_status_idx` (`local_purchase_order_id`, `status`),
    ADD CONSTRAINT `local_goods_receipts_current_invoice_id_foreign` FOREIGN KEY (`current_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `local_goods_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `local_goods_receipts_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `local_invoice_goods_receipts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `local_purchase_order_id` BIGINT UNSIGNED NOT NULL,
    `local_goods_receipt_id` BIGINT UNSIGNED NOT NULL,
    `state` VARCHAR(20) NOT NULL,
    `gr_number_snapshot` VARCHAR(100) NOT NULL,
    `gr_amount_snapshot` DECIMAL(20, 2) NULL DEFAULT NULL,
    `reserved_at` TIMESTAMP NULL DEFAULT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `released_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `local_invoice_gr_invoice_state_idx` (`local_invoice_id`, `state`),
    KEY `local_invoice_gr_receipt_state_idx` (`local_goods_receipt_id`, `state`),
    KEY `local_invoice_goods_receipts_local_purchase_order_id_foreign` (`local_purchase_order_id`),
    CONSTRAINT `local_invoice_goods_receipts_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_goods_receipts_local_purchase_order_id_foreign` FOREIGN KEY (`local_purchase_order_id`) REFERENCES `local_purchase_orders` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_goods_receipts_local_goods_receipt_id_foreign` FOREIGN KEY (`local_goods_receipt_id`) REFERENCES `local_goods_receipts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_voucher_sequences` (
    `period` CHAR(4) NOT NULL,
    `last_number` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_vouchers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `payment_batch_id` BIGINT UNSIGNED NOT NULL,
    `payment_group_id` BIGINT UNSIGNED NOT NULL,
    `payment_item_id` BIGINT UNSIGNED NOT NULL,
    `voucher_number` VARCHAR(100) NOT NULL,
    `voucher_date` DATE NOT NULL,
    `payment_method` VARCHAR(10) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'FINAL',
    `supplier_name_snapshot` VARCHAR(150) NOT NULL,
    `bank_name_snapshot` VARCHAR(100) NOT NULL,
    `bank_account_snapshot` VARCHAR(50) NOT NULL,
    `bank_account_holder_snapshot` VARCHAR(150) NOT NULL,
    `npwp_snapshot` VARCHAR(50) NULL DEFAULT NULL,
    `invoice_number_snapshot` VARCHAR(100) NOT NULL,
    `po_number_snapshot` VARCHAR(100) NOT NULL,
    `gr_references_snapshot` TEXT NOT NULL,
    `dpp_snapshot` DECIMAL(20, 2) NOT NULL,
    `ppn_snapshot` DECIMAL(20, 2) NOT NULL,
    `pph_snapshot` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `net_payable_snapshot` DECIMAL(20, 2) NOT NULL,
    `amount` DECIMAL(20, 2) NOT NULL,
    `terbilang_snapshot` TEXT NOT NULL,
    `remarks_snapshot` TEXT NULL DEFAULT NULL,
    `finalized_by` BIGINT UNSIGNED NOT NULL,
    `finalized_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_invoice_vouchers_local_invoice_id_unique` (`local_invoice_id`),
    UNIQUE KEY `local_invoice_vouchers_payment_item_id_unique` (`payment_item_id`),
    UNIQUE KEY `local_invoice_vouchers_voucher_number_unique` (`voucher_number`),
    KEY `local_voucher_batch_group_idx` (`payment_batch_id`, `payment_group_id`),
    KEY `local_invoice_vouchers_payment_group_id_foreign` (`payment_group_id`),
    KEY `local_invoice_vouchers_finalized_by_foreign` (`finalized_by`),
    CONSTRAINT `local_invoice_vouchers_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_vouchers_payment_batch_id_foreign` FOREIGN KEY (`payment_batch_id`) REFERENCES `payment_batches` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_vouchers_payment_group_id_foreign` FOREIGN KEY (`payment_group_id`) REFERENCES `payment_groups` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_vouchers_payment_item_id_foreign` FOREIGN KEY (`payment_item_id`) REFERENCES `payment_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_vouchers_finalized_by_foreign` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `local_invoice_voucher_id` BIGINT UNSIGNED NOT NULL,
    `payment_item_id` BIGINT UNSIGNED NOT NULL,
    `expected_amount` DECIMAL(20, 2) NOT NULL,
    `actual_paid_total` DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    `status` VARCHAR(30) NOT NULL DEFAULT 'OPEN',
    `finalized_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `finalized_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `updated_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_invoice_payments_local_invoice_id_unique` (`local_invoice_id`),
    UNIQUE KEY `local_invoice_payments_local_invoice_voucher_id_unique` (`local_invoice_voucher_id`),
    UNIQUE KEY `local_invoice_payments_payment_item_id_unique` (`payment_item_id`),
    KEY `local_invoice_payments_finalized_by_foreign` (`finalized_by`),
    KEY `local_invoice_payments_created_by_foreign` (`created_by`),
    KEY `local_invoice_payments_updated_by_foreign` (`updated_by`),
    CONSTRAINT `local_invoice_payments_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_payments_local_invoice_voucher_id_foreign` FOREIGN KEY (`local_invoice_voucher_id`) REFERENCES `local_invoice_vouchers` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_payments_payment_item_id_foreign` FOREIGN KEY (`payment_item_id`) REFERENCES `payment_items` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_payments_finalized_by_foreign` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `local_invoice_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_payments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_invoice_payment_transfers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_payment_id` BIGINT UNSIGNED NOT NULL,
    `sequence_no` INT UNSIGNED NOT NULL,
    `transfer_type` VARCHAR(20) NOT NULL,
    `primary_guard` BIGINT UNSIGNED NULL DEFAULT NULL,
    `amount` DECIMAL(20, 2) NOT NULL,
    `transfer_reference` VARCHAR(100) NOT NULL,
    `transfer_date` DATE NOT NULL,
    `correction_reason` TEXT NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `entered_by` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `local_invoice_payment_transfers_primary_guard_unique` (`primary_guard`),
    UNIQUE KEY `local_payment_transfer_sequence_unique` (`local_invoice_payment_id`, `sequence_no`),
    KEY `local_payment_transfer_reference_idx` (`transfer_reference`),
    KEY `local_invoice_payment_transfers_entered_by_foreign` (`entered_by`),
    CONSTRAINT `local_invoice_payment_transfers_local_invoice_payment_id_foreign` FOREIGN KEY (`local_invoice_payment_id`) REFERENCES `local_invoice_payments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `local_invoice_payment_transfers_entered_by_foreign` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_overpayment_refunds` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `local_invoice_payment_id` BIGINT UNSIGNED NOT NULL,
    `local_invoice_id` BIGINT UNSIGNED NOT NULL,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `overpayment_amount` DECIMAL(20, 2) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    `refund_amount` DECIMAL(20, 2) NULL DEFAULT NULL,
    `refund_reference` VARCHAR(100) NULL DEFAULT NULL,
    `refund_date` DATE NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `settled_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `settled_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `supplier_overpayment_refunds_local_invoice_payment_id_unique` (`local_invoice_payment_id`),
    KEY `supplier_overpayment_supplier_status_idx` (`supplier_id`, `status`),
    KEY `supplier_overpayment_refunds_local_invoice_id_foreign` (`local_invoice_id`),
    KEY `supplier_overpayment_refunds_created_by_foreign` (`created_by`),
    KEY `supplier_overpayment_refunds_settled_by_foreign` (`settled_by`),
    CONSTRAINT `supplier_overpayment_refunds_local_invoice_payment_id_foreign` FOREIGN KEY (`local_invoice_payment_id`) REFERENCES `local_invoice_payments` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `supplier_overpayment_refunds_local_invoice_id_foreign` FOREIGN KEY (`local_invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `supplier_overpayment_refunds_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `supplier_overpayment_refunds_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `supplier_overpayment_refunds_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local_finance_audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `auditable_type` VARCHAR(255) NOT NULL,
    `auditable_id` BIGINT UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `actor_id` BIGINT UNSIGNED NOT NULL,
    `before_values` JSON NULL DEFAULT NULL,
    `after_values` JSON NULL DEFAULT NULL,
    `metadata` JSON NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    KEY `local_finance_auditable_idx` (`auditable_type`, `auditable_id`),
    KEY `local_finance_action_date_idx` (`action`, `created_at`),
    KEY `local_finance_audit_logs_actor_id_foreign` (`actor_id`),
    CONSTRAINT `local_finance_audit_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `local_invoices`
    MODIFY COLUMN `status` ENUM(
        'WAITING_PHYSICAL_DOCUMENT',
        'UNDER_VERIFICATION',
        'UNDER_REVIEW',
        'NEED_REVISION',
        'READY_TO_PAY',
        'APPROVED',
        'PAYMENT_SCHEDULED',
        'PAID',
        'COMPLETED',
        'EXPIRED',
        'REJECTED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT';


-- ==============================================================================
-- 12. Migration: 2026_09_17_000001_remove_local_document_type_unique_constraint
-- ==============================================================================

ALTER TABLE `local_invoice_documents`
    DROP INDEX `local_document_type_unique`,
    DROP INDEX `local_invoice_documents_file_path_unique`;


-- ==============================================================================
-- 13. Migration: 2026_09_17_000002_cancel_empty_payment_batches
-- ==============================================================================

UPDATE `payment_batches`
SET `status` = 'CANCELLED',
    `total_subtotal` = 0.00,
    `total_bank_fee` = 0.00,
    `total_net_amount` = 0.00
WHERE `status` = 'DRAFT'
  AND (
      `total_net_amount` <= 0
      OR `id` NOT IN (
          SELECT DISTINCT `pg`.`payment_batch_id`
          FROM `payment_groups` `pg`
          JOIN `payment_items` `pi` ON `pi`.`payment_group_id` = `pg`.`id`
          WHERE `pg`.`status` != 'CANCELLED'
            AND `pi`.`status` = 'ACTIVE'
      )
  );


-- ==============================================================================
-- 14. Migration: 2026_09_17_000003_widen_gr_references_on_local_invoices_table
-- ==============================================================================

ALTER TABLE `local_invoices`
    MODIFY COLUMN `internal_gr_reference` TEXT NULL DEFAULT NULL,
    MODIFY COLUMN `manual_gr_reference` TEXT NULL DEFAULT NULL;

ALTER TABLE `local_invoice_revisions`
    MODIFY COLUMN `internal_gr_reference` TEXT NULL DEFAULT NULL,
    MODIFY COLUMN `manual_gr_reference` TEXT NULL DEFAULT NULL;


-- ==============================================================================
-- 15. Migration: 2026_09_21_000001_add_delivery_reminder_sent_at_to_local_invoices_table
-- ==============================================================================

ALTER TABLE `local_invoices`
    ADD COLUMN `delivery_reminder_sent_at` TIMESTAMP NULL DEFAULT NULL AFTER `scheduled_physical_delivery_date`;


-- ==============================================================================
-- 16. Migration: 2026_09_23_000001_add_account_status_to_users_table
-- ==============================================================================

ALTER TABLE `users`
    ADD COLUMN `account_status` ENUM('PENDING', 'REVISION', 'ACTIVE', 'REJECTED') NOT NULL DEFAULT 'ACTIVE' AFTER `is_active`,
    ADD INDEX `users_account_status_index` (`account_status`);


-- ==============================================================================
-- 17. Migration: 2026_09_23_000002_add_registration_fields_to_suppliers_table
-- ==============================================================================

ALTER TABLE `suppliers`
    ADD COLUMN `company_title` VARCHAR(50) NULL DEFAULT NULL AFTER `user_id`,
    ADD COLUMN `nib` VARCHAR(50) NULL DEFAULT NULL AFTER `npwp`,
    ADD COLUMN `nib_fingerprint` CHAR(64) NULL DEFAULT NULL AFTER `nib`,
    ADD COLUMN `tax_identity_fingerprint` CHAR(64) NULL DEFAULT NULL AFTER `nib_fingerprint`,
    ADD UNIQUE KEY `suppliers_nib_fingerprint_unique` (`nib_fingerprint`),
    ADD UNIQUE KEY `suppliers_tax_identity_fingerprint_unique` (`tax_identity_fingerprint`);

-- Backfill tax_identity_fingerprint for existing suppliers with an NPWP
UPDATE `suppliers`
SET `tax_identity_fingerprint` = SHA2(REGEXP_REPLACE(`npwp`, '[^0-9A-Za-z]', ''), 256)
WHERE `npwp` IS NOT NULL
  AND `npwp` != ''
  AND REGEXP_REPLACE(`npwp`, '[^0-9A-Za-z]', '') != ''
  AND `tax_identity_fingerprint` IS NULL;


-- ==============================================================================
-- 18. Migration: 2026_09_23_000003_add_bank_account_and_skd_to_supplier_master_documents
-- ==============================================================================

ALTER TABLE `supplier_master_documents`
    ADD COLUMN `supplier_bank_account_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `uploaded_by`,
    ADD CONSTRAINT `supplier_master_documents_supplier_bank_account_id_foreign` FOREIGN KEY (`supplier_bank_account_id`) REFERENCES `supplier_bank_accounts` (`id`) ON DELETE SET NULL;

ALTER TABLE `supplier_master_documents`
    MODIFY COLUMN `document_type` ENUM('NIB', 'NPWP', 'SPPKP', 'SURAT_PERNYATAAN_REKENING', 'SKD', 'OTHER') NOT NULL;


-- ==============================================================================
-- 19. Migration: 2026_09_23_000004_create_supplier_registration_attempts_table
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `supplier_registration_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `attempt_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('PENDING', 'REVISION', 'APPROVED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
    `submission_snapshot` LONGTEXT NOT NULL,
    `submission_checksum` CHAR(64) NOT NULL,
    `submitted_at` TIMESTAMP NULL DEFAULT NULL,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `revision_reason` TEXT NULL DEFAULT NULL,
    `rejection_reason` TEXT NULL DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `rejected_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `supplier_registration_attempts_user_id_attempt_number_index` (`user_id`, `attempt_number`),
    KEY `supplier_registration_attempts_status_index` (`status`),
    KEY `supplier_registration_attempts_submitted_at_index` (`submitted_at`),
    KEY `supplier_registration_attempts_reviewed_by_index` (`reviewed_by`),
    CONSTRAINT `supplier_registration_attempts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_registration_attempts_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 20. Migration: 2026_09_23_000005_create_supplier_registration_access_table
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `supplier_registration_access` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `registration_reference` VARCHAR(32) NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `issued_at` TIMESTAMP NULL DEFAULT NULL,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reg_access_user_unique` (`user_id`),
    UNIQUE KEY `reg_access_ref_unique` (`registration_reference`),
    KEY `reg_access_ref_rev_idx` (`registration_reference`, `revoked_at`),
    CONSTRAINT `supplier_registration_access_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 21. Migration: 2026_09_23_000006_create_supplier_registration_audits_table
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `supplier_registration_audits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attempt_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `actor_role` VARCHAR(50) NULL DEFAULT NULL,
    `event` VARCHAR(100) NOT NULL,
    `reason` TEXT NULL DEFAULT NULL,
    `metadata` JSON NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `reg_audits_user_created_idx` (`user_id`, `created_at`),
    KEY `reg_audits_attempt_created_idx` (`attempt_id`, `created_at`),
    KEY `reg_audits_event_idx` (`event`),
    KEY `supplier_registration_audits_actor_id_foreign` (`actor_id`),
    CONSTRAINT `supplier_registration_audits_attempt_id_foreign` FOREIGN KEY (`attempt_id`) REFERENCES `supplier_registration_attempts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_registration_audits_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `supplier_registration_audits_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==============================================================================
-- 22. Migration: 2026_09_24_140000_add_qty_and_description_to_local_goods_receipts_table
-- ==============================================================================

ALTER TABLE `local_goods_receipts`
    ADD COLUMN `qty` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000 AFTER `received_amount`,
    ADD COLUMN `description` TEXT NULL DEFAULT NULL AFTER `qty`;


-- ==============================================================================
-- 23. Migration: 2026_09_24_150000_make_gr_received_amount_nullable_and_add_qty_snapshot
-- ==============================================================================

ALTER TABLE `local_goods_receipts`
    MODIFY COLUMN `received_amount` DECIMAL(20, 2) NULL DEFAULT NULL;

ALTER TABLE `local_invoice_goods_receipts`
    MODIFY COLUMN `gr_amount_snapshot` DECIMAL(20, 2) NULL DEFAULT NULL,
    ADD COLUMN `gr_qty_snapshot` DECIMAL(15, 4) NULL DEFAULT NULL AFTER `gr_amount_snapshot`;


-- ==============================================================================
-- 24. Migration: 2026_09_24_160000_drop_received_amount_from_local_goods_receipts_tables
-- ==============================================================================

ALTER TABLE `local_goods_receipts`
    DROP COLUMN `received_amount`;

ALTER TABLE `local_invoice_goods_receipts`
    DROP COLUMN `gr_amount_snapshot`;


-- ==============================================================================
-- 25. Migration: 2026_09_25_000001_add_ready_to_pay_at_status_index_to_local_invoices_table
-- ==============================================================================

ALTER TABLE `local_invoices`
    ADD INDEX `local_invoices_ready_to_pay_status_idx` (`ready_to_pay_at`, `status`);


-- ==============================================================================
-- 26. Migration: 2026_09_25_000002_add_price_comparison_performance_indexes
-- ==============================================================================

ALTER TABLE `quotations`
    ADD INDEX `quotations_pr_id_status_index` (`pr_id`, `status`);

ALTER TABLE `quotation_items`
    ADD INDEX `quotation_items_pr_item_quotation_index` (`pr_item_id`, `quotation_id`);

ALTER TABLE `purchase_requisitions`
    ADD INDEX `pr_created_at_status_index` (`created_at`, `status`);



-- ==============================================================================
-- Ledger Synchronization: migrations table
-- ==============================================================================

SET @adasi_schema_batch := (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_08_000002_create_local_invoice_domain', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_08_000002_create_local_invoice_domain');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_11_000002_create_vendor_master_v2_tables', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_11_000002_create_vendor_master_v2_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_11_000003_add_v2_fields_to_local_invoices_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_11_000003_add_v2_fields_to_local_invoices_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_11_000004_create_local_invoice_verification_tables', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_11_000004_create_local_invoice_verification_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_11_000005_create_employee_and_ga_claim_tables', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_11_000005_create_employee_and_ga_claim_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_11_000006_create_unified_payment_batches_tables', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_11_000006_create_unified_payment_batches_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_14_000001_harden_local_invoice_and_payment_invariants', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_14_000001_harden_local_invoice_and_payment_invariants');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_14_000002_add_tax_invoice_number_to_local_invoices', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_14_000002_add_tax_invoice_number_to_local_invoices');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_14_000003_add_cancelled_status_to_payment_groups', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_14_000003_add_cancelled_status_to_payment_groups');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_15_000001_implement_local_supplier_whole_gr_settlement', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_15_000001_implement_local_supplier_whole_gr_settlement');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_17_000001_remove_local_document_type_unique_constraint', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_17_000001_remove_local_document_type_unique_constraint');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_17_000002_cancel_empty_payment_batches', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_17_000002_cancel_empty_payment_batches');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_17_000003_widen_gr_references_on_local_invoices_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_17_000003_widen_gr_references_on_local_invoices_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_21_000001_add_delivery_reminder_sent_at_to_local_invoices_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_21_000001_add_delivery_reminder_sent_at_to_local_invoices_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_23_000001_add_account_status_to_users_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000001_add_account_status_to_users_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_23_000002_add_registration_fields_to_suppliers_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000002_add_registration_fields_to_suppliers_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_23_000003_add_bank_account_and_skd_to_supplier_master_documents', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000003_add_bank_account_and_skd_to_supplier_master_documents');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_23_000004_create_supplier_registration_attempts_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000004_create_supplier_registration_attempts_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_23_000005_create_supplier_registration_access_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000005_create_supplier_registration_access_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_23_000006_create_supplier_registration_audits_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_23_000006_create_supplier_registration_audits_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_24_140000_add_qty_and_description_to_local_goods_receipts_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_24_140000_add_qty_and_description_to_local_goods_receipts_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_24_150000_make_gr_received_amount_nullable_and_add_qty_snapshot', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_24_150000_make_gr_received_amount_nullable_and_add_qty_snapshot');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_24_160000_drop_received_amount_from_local_goods_receipts_tables', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_24_160000_drop_received_amount_from_local_goods_receipts_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_25_000001_add_ready_to_pay_at_status_index_to_local_invoices_table', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_25_000001_add_ready_to_pay_at_status_index_to_local_invoices_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_25_000002_add_price_comparison_performance_indexes', @adasi_schema_batch
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_25_000002_add_price_comparison_performance_indexes');


-- ==============================================================================
-- Verification Output
-- ==============================================================================

SELECT `migration`, `batch`
FROM `migrations`
WHERE `migration` IN (
    '2026_09_08_000002_create_local_invoice_domain',
    '2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga',
    '2026_09_11_000002_create_vendor_master_v2_tables',
    '2026_09_11_000003_add_v2_fields_to_local_invoices_table',
    '2026_09_11_000004_create_local_invoice_verification_tables',
    '2026_09_11_000005_create_employee_and_ga_claim_tables',
    '2026_09_11_000006_create_unified_payment_batches_tables',
    '2026_09_14_000001_harden_local_invoice_and_payment_invariants',
    '2026_09_14_000002_add_tax_invoice_number_to_local_invoices',
    '2026_09_14_000003_add_cancelled_status_to_payment_groups',
    '2026_09_15_000001_implement_local_supplier_whole_gr_settlement',
    '2026_09_17_000001_remove_local_document_type_unique_constraint',
    '2026_09_17_000002_cancel_empty_payment_batches',
    '2026_09_17_000003_widen_gr_references_on_local_invoices_table',
    '2026_09_21_000001_add_delivery_reminder_sent_at_to_local_invoices_table',
    '2026_09_23_000001_add_account_status_to_users_table',
    '2026_09_23_000002_add_registration_fields_to_suppliers_table',
    '2026_09_23_000003_add_bank_account_and_skd_to_supplier_master_documents',
    '2026_09_23_000004_create_supplier_registration_attempts_table',
    '2026_09_23_000005_create_supplier_registration_access_table',
    '2026_09_23_000006_create_supplier_registration_audits_table',
    '2026_09_24_140000_add_qty_and_description_to_local_goods_receipts_table',
    '2026_09_24_150000_make_gr_received_amount_nullable_and_add_qty_snapshot',
    '2026_09_24_160000_drop_received_amount_from_local_goods_receipts_tables',
    '2026_09_25_000001_add_ready_to_pay_at_status_index_to_local_invoices_table',
    '2026_09_25_000002_add_price_comparison_performance_indexes'
)
ORDER BY `migration`;

SET SQL_MODE = @OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS = @OLD_UNIQUE_CHECKS;

SELECT DATABASE() AS `selected_database`, CURRENT_TIMESTAMP AS `completed_at`;
