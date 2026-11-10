-- ADASI accounts: Finance 01, GA 01, Supplier Local 01-03.
-- Revised for MariaDB 11.4.13 hosting; NOT compatible with MySQL.
-- Select adaw2196_supplierportal in phpMyAdmin and import this ENTIRE file.
-- Password for all five new accounts: password (bcrypt cost 12).
-- Existing accounts are never overwritten. Any conflicting email aborts all inserts.
-- A single server-side block keeps ROW_COUNT() independent of client queries.
-- No procedure, schema object, or hardcoded user ID is created.

SET NAMES utf8mb4;
SET SESSION time_zone = '+00:00';

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    DECLARE EXIT HANDLER FOR SQLWARNING
    BEGIN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Account import rolled back because MariaDB returned a warning.';
    END;

    SET @adasi_account_password_hash = '$2y$12$DVz/KL1E7yWMtXtKHTv41O6AIyfdfAGKXAJXwadys9jW5Ywtu9TXa';
    SET @adasi_users_created = 0;
    SET @adasi_profiles_created = 0;
    SET @adasi_scopes_created = 0;

    START TRANSACTION;

    SET @adasi_accounts_schema_ok = (
        (SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('users', 'suppliers', 'supplier_scopes')
           AND ENGINE = 'InnoDB') = 3
        AND
        (SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND (
               (TABLE_NAME = 'users' AND COLUMN_NAME IN (
                   'id', 'name', 'email', 'password', 'role', 'is_active',
                   'account_status', 'must_change_password', 'created_at', 'updated_at'
               ))
               OR
               (TABLE_NAME = 'suppliers' AND COLUMN_NAME IN (
                   'user_id', 'company_name', 'address', 'phone', 'npwp', 'nib',
                   'category', 'vendor_category', 'is_pkp', 'pic_name', 'pic_email',
                   'pic_phone', 'payment_term_days', 'created_at', 'updated_at'
               ))
               OR
               (TABLE_NAME = 'supplier_scopes' AND COLUMN_NAME IN (
                   'supplier_id', 'scope', 'created_at', 'updated_at'
               ))
           )) = 29
        AND EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
              AND LOCATE('''finance''', COLUMN_TYPE) > 0
              AND LOCATE('''ga''', COLUMN_TYPE) > 0
              AND LOCATE('''supplier''', COLUMN_TYPE) > 0
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status'
              AND LOCATE('''ACTIVE''', COLUMN_TYPE) > 0
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_scopes' AND COLUMN_NAME = 'scope'
              AND LOCATE('''local''', COLUMN_TYPE) > 0
        )
        AND EXISTS (
            SELECT 1 FROM information_schema.STATISTICS AS email_index
            WHERE email_index.TABLE_SCHEMA = DATABASE()
              AND email_index.TABLE_NAME = 'users'
              AND email_index.COLUMN_NAME = 'email'
              AND email_index.NON_UNIQUE = 0
              AND email_index.SEQ_IN_INDEX = 1
              AND NOT EXISTS (
                  SELECT 1 FROM information_schema.STATISTICS AS extra_column
                  WHERE extra_column.TABLE_SCHEMA = email_index.TABLE_SCHEMA
                    AND extra_column.TABLE_NAME = email_index.TABLE_NAME
                    AND extra_column.INDEX_NAME = email_index.INDEX_NAME
                    AND extra_column.SEQ_IN_INDEX > 1
              )
        )
    );
    
    
    IF @adasi_accounts_schema_ok IS NULL OR @adasi_accounts_schema_ok <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Account import aborted: incompatible ADASI schema or storage engine.';
    END IF;

    SET @adasi_account_email_conflicts = (
        SELECT COUNT(*) FROM users
        WHERE email IN (
            'finance01@example.com', 'ga01@example.com',
            'supplier.local01@example.com', 'supplier.local02@example.com',
            'supplier.local03@example.com'
        )
    );
    
    
    IF @adasi_account_email_conflicts <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Account import aborted: one or more target emails already exist; no account was changed.';
    END IF;

    INSERT INTO users (
        name, email, password, role, is_active, account_status,
        must_change_password, created_at, updated_at
    )
    SELECT
        account_seed.name, account_seed.email, @adasi_account_password_hash,
        account_seed.role, 1, 'ACTIVE', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    FROM (
        SELECT 'Finance 01' AS name, 'finance01@example.com' AS email, 'finance' AS role
        UNION ALL SELECT 'GA 01', 'ga01@example.com', 'ga'
        UNION ALL SELECT 'Supplier Local 01', 'supplier.local01@example.com', 'supplier'
        UNION ALL SELECT 'Supplier Local 02', 'supplier.local02@example.com', 'supplier'
        UNION ALL SELECT 'Supplier Local 03', 'supplier.local03@example.com', 'supplier'
    ) AS account_seed
    WHERE @adasi_accounts_schema_ok = 1
      AND @adasi_account_email_conflicts = 0;
    
    SET @adasi_users_created = ROW_COUNT();
    
    INSERT INTO suppliers (
        user_id, company_name, address, phone, npwp, nib,
        category, vendor_category, is_pkp, pic_name, pic_email, pic_phone,
        payment_term_days, created_at, updated_at
    )
    SELECT
        account_user.id, CONCAT('PT ', account_user.name),
        NULL, NULL, NULL, NULL, 'Barang', 'Barang', 0,
        account_user.name, account_user.email, NULL, 30,
        UTC_TIMESTAMP(), UTC_TIMESTAMP()
    FROM users AS account_user
    WHERE @adasi_users_created = 5
      AND account_user.role = 'supplier'
      AND account_user.email IN (
          'supplier.local01@example.com', 'supplier.local02@example.com',
          'supplier.local03@example.com'
      );
    
    SET @adasi_profiles_created = ROW_COUNT();
    
    INSERT INTO supplier_scopes (supplier_id, scope, created_at, updated_at)
    SELECT account_user.id, 'local', UTC_TIMESTAMP(), UTC_TIMESTAMP()
    FROM users AS account_user
    WHERE @adasi_users_created = 5
      AND @adasi_profiles_created = 3
      AND account_user.role = 'supplier'
      AND account_user.email IN (
          'supplier.local01@example.com', 'supplier.local02@example.com',
          'supplier.local03@example.com'
      );
    
    SET @adasi_scopes_created = ROW_COUNT();
    
    
    IF @adasi_users_created <> 5
       OR @adasi_profiles_created <> 3
       OR @adasi_scopes_created <> 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Account import rolled back: expected exactly 5 users, 3 profiles, 3 local scopes.';
    END IF;

    COMMIT;
END$$

DELIMITER ;

-- Separate result set: do not return results from inside the compound block.
SELECT DATABASE() AS selected_database,
       'CREATED' AS result,
       @adasi_users_created AS users_created,
       @adasi_profiles_created AS supplier_profiles_created,
       @adasi_scopes_created AS local_scopes_created;
