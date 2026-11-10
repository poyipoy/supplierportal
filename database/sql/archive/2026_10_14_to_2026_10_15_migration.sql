-- ADASI Portal Supplier: forward SQL for 14-15 October 2026 (9 migrations).
-- Generated from database/migrations/2026_10_14_*.php and 2026_10_15_*.php.
-- Target: MySQL 8.0.16+ / MariaDB 10.5+; InnoDB, utf8mb4_unicode_ci.
-- Production baseline inspected from: adaw2196_supplierportal (4).sql.
-- Dump engine: MariaDB 11.4.13-MariaDB-cll-lve; local engine: MySQL 8.0.30.
-- All 9 migrations' schema changes are absent from that dump. Its 110 ledger
-- rows end on 2026_10_12 (batch 40); none of these 9 names is in the dump ledger.
-- The live ledger may have since been copied from local: existing matching
-- entries are preserved; missing entries are inserted only AFTER successful DDL.
-- No connection to the live production server was made.
-- SQL mode is saved and made strict for this import session BEFORE creating
-- the procedure: stored routines retain their creation-time SQL mode.
-- Reimport this entire file after an early strict-mode guard failure; running
-- SET sql_mode followed by CALL on the OLD routine does not repair its mode.
-- The same restart is safe for an early version-parse error (#1292): version
-- parsing precedes all application DDL and ledger writes.
--
-- BEFORE IMPORT:
-- 1. Select the correct database in phpMyAdmin or with USE <database>.
-- 2. Back up schema AND data; rehearse on a restored staging database.
-- 3. All migrations dated before 2026_10_14 must already be applied.
--    All 9 migrations' PHYSICAL changes below must still be absent, as in the
--    supplied dump. Their ledger entries may already exist (copied from local).
--    If some physical changes are already applied, prepare a narrower script;
--    do not remove ledger rows or drop populated tables to bypass guards.
-- 4. Stop application writes and queue workers; run one migration session only.
--    Do not run Laravel migrations concurrently or import with --force.
-- 5. Use a client supporting DELIMITER and a migration account allowed to
--    CREATE/ALTER tables, CREATE/EXECUTE/DROP PROCEDURE, and INSERT migrations.
--
-- This is an alternative to the 9 PHP migrations, not an additional step.
-- No seed, UPDATE, data backfill, or document revaluation is performed.
-- FK/CHECK/strict-mode protections stay enabled.
-- MySQL/MariaDB DDL implicitly commits: this entire file is NOT atomic.
-- The procedure stops on the first failure; only completed stages get NEW logs.
-- Existing copied ledger entries cannot prove that a stage completed. On error,
-- do not resume application writes merely because migrate:status says Ran.
-- On failure keep the error, inspect SHOW CREATE TABLE and migrations, then
-- reconcile before resuming. Rerunning this whole file is intentionally refused.
-- A SQL error cannot roll back successful earlier DDL.
--
-- ROLLBACK: prefer roll-forward after any application writes. Restore the
-- pre-import backup only under an approved recovery plan (later writes would
-- be lost). PHP down() methods refuse rollback if any new field/history/table
-- contains data: preserve those guards. If every new structure is unused,
-- an explicitly approved rollback must follow reverse migration order, drop
-- dependent snapshots before reference prices, and remove ledger entries only
-- after their corresponding schema reversal. No destructive rollback is run here.

SELECT DATABASE() AS selected_database, VERSION() AS server_version;
SELECT migration, batch FROM migrations
WHERE migration >= '2026_10_14_' AND migration < '2026_10_16_'
ORDER BY migration;

SET @adasi_20261014_previous_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = IF(
    FIND_IN_SET('STRICT_TRANS_TABLES', @adasi_20261014_previous_sql_mode) > 0
    OR FIND_IN_SET('STRICT_ALL_TABLES', @adasi_20261014_previous_sql_mode) > 0,
    @adasi_20261014_previous_sql_mode,
    CONCAT_WS(',', NULLIF(@adasi_20261014_previous_sql_mode, ''), 'STRICT_TRANS_TABLES')
);

-- Replace only this script's helper routine, which may remain after a failed
-- CALL. This does not drop any application table or remove any ledger entry.
DROP PROCEDURE IF EXISTS adasi_migrate_20261014_20261015;

DELIMITER $$
CREATE PROCEDURE adasi_migrate_20261014_20261015()
SQL SECURITY INVOKER
BEGIN
    DECLARE migration_batch INT;
    DECLARE version_numeric VARCHAR(64);
    DECLARE version_major INT;
    DECLARE version_minor INT;
    DECLARE version_patch INT;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Select the target database before import.';
    END IF;

    -- Extract the numeric triplet before casting. For example,
    -- 11.4.13-MariaDB-cll-lve must yield 11, 4, 13 without truncation warnings.
    SET version_numeric = REGEXP_SUBSTR(VERSION(), '^[0-9]+[.][0-9]+[.][0-9]+');
    IF version_numeric IS NULL OR version_numeric = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot parse server version. Inspect VERSION() before import.';
    END IF;
    SET version_major = CAST(SUBSTRING_INDEX(version_numeric, '.', 1) AS UNSIGNED);
    SET version_minor = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(version_numeric, '.', 2), '.', -1) AS UNSIGNED);
    SET version_patch = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(version_numeric, '.', 3), '.', -1) AS UNSIGNED);

    IF VERSION() LIKE '%MariaDB%' THEN
        IF version_major < 10 OR (version_major = 10 AND version_minor < 5) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This script requires MariaDB 10.5 or newer.';
        END IF;
    ELSEIF version_major < 8 OR (version_major = 8 AND version_minor = 0 AND version_patch < 16) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This script requires MySQL 8.0.16 or newer (enforced CHECK).';
    END IF;

    IF @@SESSION.foreign_key_checks <> 1 OR @@SESSION.unique_checks <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enable foreign_key_checks and unique_checks before import.';
    END IF;
    IF FIND_IN_SET('STRICT_TRANS_TABLES', @@SESSION.sql_mode) = 0
       AND FIND_IN_SET('STRICT_ALL_TABLES', @@SESSION.sql_mode) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A strict SQL mode is required before import.';
    END IF;
    IF @@SESSION.autocommit <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use a fresh autocommit session with no active transaction.';
    END IF;

    -- A matching ledger row is allowed: the user copied the ledger from local.
    -- Duplicate rows are not silently repaired by this schema-only catch-up.
    IF EXISTS (
        SELECT migration FROM migrations WHERE migration IN (
            '2026_10_14_000001_add_pr_number_to_local_purchase_orders',
            '2026_10_14_000002_add_logistics_terms_to_purchase_requisitions',
            '2026_10_14_000003_add_logistics_terms_to_purchase_orders',
            '2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards',
            '2026_10_14_000005_create_pr_item_award_events_table',
            '2026_10_14_000006_create_material_reference_prices_table',
            '2026_10_14_000007_create_award_reference_price_snapshots_table',
            '2026_10_15_000001_add_po_and_gr_to_local_invoice_documents',
            '2026_10_15_000100_create_master_data_audit_logs_table'
        ) GROUP BY migration HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate selected migration names in ledger. Reconcile duplicate rows first.';
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB'
          AND TABLE_NAME IN ('migrations', 'users', 'local_purchase_orders',
              'purchase_requisitions', 'purchase_orders', 'pr_item_awards',
              'material_masters', 'exchange_rates', 'local_invoice_documents')) <> 9 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing prerequisite InnoDB tables. Apply earlier migrations first.';
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
            'pr_item_award_events', 'material_reference_prices',
            'award_reference_price_snapshots', 'master_data_audit_logs')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A selected new table already exists. Inspect partial or already-applied schema.';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND (
            (TABLE_NAME = 'local_purchase_orders' AND COLUMN_NAME = 'pr_number')
            OR (TABLE_NAME IN ('purchase_requisitions', 'purchase_orders')
                AND COLUMN_NAME IN ('port_of_discharge', 'incoterm'))
            OR (TABLE_NAME = 'pr_item_awards' AND COLUMN_NAME IN (
                'ordered_qty', 'ordered_weight_per_unit', 'ordered_total_weight_kg',
                'ordered_price_per_kg', 'ordered_amount', 'spec_accepted_by',
                'spec_accepted_at', 'spec_acceptance_snapshot', 'spec_acceptance_hash'))
        )) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A selected new column already exists. Inspect partial or already-applied schema.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'local_invoice_documents'
          AND COLUMN_NAME = 'document_type' AND IS_NULLABLE = 'NO'
          AND COLUMN_TYPE = 'enum(''invoice'',''tax_invoice'',''delivery_note'',''supporting'')') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unexpected local_invoice_documents.document_type. Inspect before changing its enum.';
    END IF;

    SELECT COALESCE(MAX(batch), 0) + 1 INTO migration_batch FROM migrations;

    -- 1. 2026_10_14_000001_add_pr_number_to_local_purchase_orders
    ALTER TABLE local_purchase_orders
        ADD COLUMN pr_number VARCHAR(100) NULL,
        ADD INDEX local_purchase_orders_pr_number_index (pr_number);
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000001_add_pr_number_to_local_purchase_orders', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000001_add_pr_number_to_local_purchase_orders');

    -- 2. 2026_10_14_000002_add_logistics_terms_to_purchase_requisitions
    ALTER TABLE purchase_requisitions
        ADD COLUMN port_of_discharge VARCHAR(30) NULL AFTER notes,
        ADD COLUMN incoterm VARCHAR(10) NULL AFTER port_of_discharge;
    ALTER TABLE purchase_requisitions
        ADD CONSTRAINT purchase_requisitions_port_of_discharge_allowed
            CHECK (CAST(port_of_discharge AS BINARY) IN ('TANJUNG_PRIOK', 'TANJUNG_PERAK')),
        ADD CONSTRAINT purchase_requisitions_incoterm_allowed
            CHECK (CAST(incoterm AS BINARY) IN ('CFR', 'CIF', 'FOB'));
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000002_add_logistics_terms_to_purchase_requisitions', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000002_add_logistics_terms_to_purchase_requisitions');

    -- 3. 2026_10_14_000003_add_logistics_terms_to_purchase_orders
    ALTER TABLE purchase_orders
        ADD COLUMN port_of_discharge VARCHAR(30) NULL AFTER notes,
        ADD COLUMN incoterm VARCHAR(10) NULL AFTER port_of_discharge;
    ALTER TABLE purchase_orders
        ADD CONSTRAINT purchase_orders_port_of_discharge_allowed
            CHECK (CAST(port_of_discharge AS BINARY) IN ('TANJUNG_PRIOK', 'TANJUNG_PERAK')),
        ADD CONSTRAINT purchase_orders_incoterm_allowed
            CHECK (CAST(incoterm AS BINARY) IN ('CFR', 'CIF', 'FOB'));
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000003_add_logistics_terms_to_purchase_orders', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000003_add_logistics_terms_to_purchase_orders');

    -- 4. 2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards
    ALTER TABLE pr_item_awards
        ADD COLUMN ordered_qty INT UNSIGNED NULL AFTER awarded_at,
        ADD COLUMN ordered_weight_per_unit DECIMAL(12,4) NULL AFTER ordered_qty,
        ADD COLUMN ordered_total_weight_kg DECIMAL(14,4) NULL AFTER ordered_weight_per_unit,
        ADD COLUMN ordered_price_per_kg DECIMAL(15,4) NULL AFTER ordered_total_weight_kg,
        ADD COLUMN ordered_amount DECIMAL(15,4) NULL AFTER ordered_price_per_kg,
        ADD COLUMN spec_accepted_by BIGINT UNSIGNED NULL AFTER ordered_amount,
        ADD COLUMN spec_accepted_at TIMESTAMP NULL AFTER spec_accepted_by,
        ADD COLUMN spec_acceptance_snapshot JSON NULL AFTER spec_accepted_at,
        ADD COLUMN spec_acceptance_hash CHAR(64) NULL AFTER spec_acceptance_snapshot,
        ADD CONSTRAINT pr_item_awards_spec_accepted_by_foreign
            FOREIGN KEY (spec_accepted_by) REFERENCES users (id) ON DELETE SET NULL;
    ALTER TABLE pr_item_awards
        ADD CONSTRAINT pr_item_awards_ordered_qty_positive
            CHECK (ordered_qty IS NULL OR ordered_qty >= 1),
        ADD CONSTRAINT pr_item_awards_ordered_values_non_negative CHECK (
            (ordered_weight_per_unit IS NULL OR ordered_weight_per_unit >= 0)
            AND (ordered_total_weight_kg IS NULL OR ordered_total_weight_kg >= 0)
            AND (ordered_price_per_kg IS NULL OR ordered_price_per_kg >= 0)
            AND (ordered_amount IS NULL OR ordered_amount >= 0)
        );
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards');

    -- 5. 2026_10_14_000005_create_pr_item_award_events_table
    CREATE TABLE pr_item_award_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pr_item_award_id BIGINT UNSIGNED NULL,
        pr_id BIGINT UNSIGNED NOT NULL,
        pr_item_id BIGINT UNSIGNED NOT NULL,
        quotation_item_id BIGINT UNSIGNED NULL,
        purchase_order_id BIGINT UNSIGNED NULL,
        event VARCHAR(40) NOT NULL,
        payload JSON NULL,
        actor_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX pr_item_award_events_pr_item_award_id_created_at_id_index (pr_item_award_id, created_at, id),
        INDEX pr_item_award_events_pr_id_created_at_index (pr_id, created_at),
        CONSTRAINT pr_item_award_events_pr_item_award_id_foreign
            FOREIGN KEY (pr_item_award_id) REFERENCES pr_item_awards (id) ON DELETE SET NULL,
        CONSTRAINT pr_item_award_events_actor_id_foreign
            FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000005_create_pr_item_award_events_table', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000005_create_pr_item_award_events_table');

    -- 6. 2026_10_14_000006_create_material_reference_prices_table
    CREATE TABLE material_reference_prices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        material_master_id BIGINT UNSIGNED NOT NULL,
        shape VARCHAR(20) NOT NULL,
        dimension_field VARCHAR(20) NOT NULL,
        dimension_min DECIMAL(10,4) NOT NULL,
        dimension_max DECIMAL(10,4) NOT NULL,
        currency VARCHAR(3) NOT NULL,
        price_per_kg DECIMAL(15,4) NOT NULL,
        valid_from DATE NOT NULL,
        valid_until DATE NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        supersedes_id BIGINT UNSIGNED NULL,
        version_no INT UNSIGNED NOT NULL DEFAULT 1,
        notes TEXT NULL,
        voided_by BIGINT UNSIGNED NULL,
        voided_at TIMESTAMP NULL,
        void_reason TEXT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL,
        INDEX material_reference_prices_lookup_index (material_master_id, shape, currency, status, valid_from),
        CONSTRAINT material_reference_prices_material_master_id_foreign
            FOREIGN KEY (material_master_id) REFERENCES material_masters (id) ON DELETE RESTRICT,
        CONSTRAINT material_reference_prices_supersedes_id_foreign
            FOREIGN KEY (supersedes_id) REFERENCES material_reference_prices (id) ON DELETE RESTRICT,
        CONSTRAINT material_reference_prices_voided_by_foreign
            FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT material_reference_prices_created_by_foreign
            FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
        CONSTRAINT material_reference_prices_updated_by_foreign
            FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ALTER TABLE material_reference_prices
        ADD CONSTRAINT material_reference_prices_shape_allowed
            CHECK (CAST(shape AS BINARY) IN ('Flat', 'Round', 'Hollow')),
        ADD CONSTRAINT material_reference_prices_dimension_matches_shape CHECK (
            (CAST(shape AS BINARY) = 'Flat' AND CAST(dimension_field AS BINARY) = 'thickness')
            OR (CAST(shape AS BINARY) IN ('Round', 'Hollow') AND CAST(dimension_field AS BINARY) = 'd_outer')
        ),
        ADD CONSTRAINT material_reference_prices_dimension_range_valid
            CHECK (dimension_min > 0 AND dimension_max >= dimension_min),
        ADD CONSTRAINT material_reference_prices_price_positive CHECK (price_per_kg > 0),
        ADD CONSTRAINT material_reference_prices_validity_range_valid
            CHECK (valid_until IS NULL OR valid_until >= valid_from),
        ADD CONSTRAINT material_reference_prices_currency_allowed
            CHECK (CAST(currency AS BINARY) IN ('USD', 'JPY', 'IDR', 'CNY')),
        ADD CONSTRAINT material_reference_prices_status_allowed
            CHECK (CAST(status AS BINARY) IN ('active', 'void'));
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000006_create_material_reference_prices_table', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000006_create_material_reference_prices_table');

    -- 7. 2026_10_14_000007_create_award_reference_price_snapshots_table
    CREATE TABLE award_reference_price_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pr_item_award_id BIGINT UNSIGNED NOT NULL,
        purchase_order_id BIGINT UNSIGNED NOT NULL,
        material_reference_price_id BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL,
        reason_code VARCHAR(40) NULL,
        evaluation_date DATE NOT NULL,
        dimension_field VARCHAR(20) NULL,
        dimension_value DECIMAL(10,4) NULL,
        reference_currency VARCHAR(3) NULL,
        reference_price_per_kg DECIMAL(15,4) NULL,
        po_currency VARCHAR(3) NOT NULL,
        reference_rate_id BIGINT UNSIGNED NULL,
        po_rate_id BIGINT UNSIGNED NULL,
        converted_reference_price_per_kg DECIMAL(15,4) NULL,
        offer_price_per_kg DECIMAL(15,4) NULL,
        diff_amount_per_kg DECIMAL(15,4) NULL,
        diff_percent DECIMAL(9,4) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY award_reference_price_snapshots_pr_item_award_id_unique (pr_item_award_id),
        CONSTRAINT award_reference_price_snapshots_pr_item_award_id_foreign
            FOREIGN KEY (pr_item_award_id) REFERENCES pr_item_awards (id) ON DELETE RESTRICT,
        CONSTRAINT award_reference_price_snapshots_purchase_order_id_foreign
            FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT,
        CONSTRAINT award_ref_price_snapshots_reference_price_fk
            FOREIGN KEY (material_reference_price_id) REFERENCES material_reference_prices (id) ON DELETE RESTRICT,
        CONSTRAINT award_reference_price_snapshots_reference_rate_id_foreign
            FOREIGN KEY (reference_rate_id) REFERENCES exchange_rates (id) ON DELETE RESTRICT,
        CONSTRAINT award_reference_price_snapshots_po_rate_id_foreign
            FOREIGN KEY (po_rate_id) REFERENCES exchange_rates (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ALTER TABLE award_reference_price_snapshots
        ADD CONSTRAINT award_reference_price_snapshots_status_coherent CHECK (
            (CAST(status AS BINARY) = 'matched' AND material_reference_price_id IS NOT NULL)
            OR (CAST(status AS BINARY) = 'not_available' AND reason_code IS NOT NULL)
        );
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_14_000007_create_award_reference_price_snapshots_table', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_14_000007_create_award_reference_price_snapshots_table');

    -- 8. 2026_10_15_000001_add_po_and_gr_to_local_invoice_documents
    ALTER TABLE local_invoice_documents MODIFY document_type
        ENUM('invoice', 'tax_invoice', 'delivery_note', 'supporting', 'purchase_order', 'goods_receipt') NOT NULL;
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_15_000001_add_po_and_gr_to_local_invoice_documents', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_15_000001_add_po_and_gr_to_local_invoice_documents');

    -- 9. 2026_10_15_000100_create_master_data_audit_logs_table
    CREATE TABLE master_data_audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(40) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(30) NOT NULL,
        `before` JSON NULL,
        `after` JSON NULL,
        actor_id BIGINT UNSIGNED NULL,
        actor_role VARCHAR(20) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX master_data_audit_logs_entity_type_entity_id_created_at_index (entity_type, entity_id, created_at),
        CONSTRAINT master_data_audit_logs_actor_id_foreign
            FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT INTO migrations (migration, batch)
        SELECT '2026_10_15_000100_create_master_data_audit_logs_table', migration_batch
        WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_15_000100_create_master_data_audit_logs_table');

    SELECT 'Completed all 9 physical migrations' AS result, migration_batch AS batch_for_new_ledger_rows;
END$$
DELIMITER ;

CALL adasi_migrate_20261014_20261015();
DROP PROCEDURE adasi_migrate_20261014_20261015;
SET SESSION sql_mode = @adasi_20261014_previous_sql_mode;
-- If the client stops at an error, the session can remain strict until closed.
-- Global server settings are never changed.

-- Post-import inspection: expect each of the 9 names exactly once. Previously
-- copied entries retain their original batch; only new rows use the new batch.
SELECT migration, batch FROM migrations
WHERE migration >= '2026_10_14_' AND migration < '2026_10_16_'
ORDER BY migration;

-- Verify physical definitions as well as the ledger.
SHOW CREATE TABLE local_purchase_orders;
SHOW CREATE TABLE purchase_requisitions;
SHOW CREATE TABLE purchase_orders;
SHOW CREATE TABLE pr_item_awards;
SHOW CREATE TABLE pr_item_award_events;
SHOW CREATE TABLE material_reference_prices;
SHOW CREATE TABLE award_reference_price_snapshots;
SHOW CREATE TABLE local_invoice_documents;
SHOW CREATE TABLE master_data_audit_logs;
-- Then run php artisan migrate:status (read-only) using the target deployment.
