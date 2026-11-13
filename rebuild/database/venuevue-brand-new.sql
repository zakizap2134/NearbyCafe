-- VenueVue (Nearby Cafe POS) — Brand New Cloud Database Schema
-- Ready for phpMyAdmin import (No default accounts, sales, or inventory)
-- Visit your site in the browser after importing to create the Owner account.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==========================================
-- 001_foundation.sql
-- ==========================================
-- Authoritative Stage 1 schema. Import only into a new, empty rebuild database.
-- Installation tool records the schema version only after all statements succeed.
CREATE TABLE app_state (
 id TINYINT UNSIGNED PRIMARY KEY,
 schema_version INT UNSIGNED NOT NULL,
 installed_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
 display_name VARCHAR(100) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('OWNER','BARISTA') NOT NULL,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX sessions_user(user_id),
 INDEX sessions_expiry(expires_at),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE login_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 failures INT UNSIGNED NOT NULL DEFAULT 0,
 window_started DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 action VARCHAR(64) NOT NULL,
 request_id VARCHAR(32) NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX audit_time(created_at),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ==========================================
-- 002_sales_inventory.sql
-- ==========================================
-- Stage 2 additions. IF NOT EXISTS allows resuming table creation after interruption.
CREATE TABLE IF NOT EXISTS products (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 category VARCHAR(60) NOT NULL,
 image_filename VARCHAR(255) NULL DEFAULT NULL,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 is_vat_exempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_variants (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id INT UNSIGNED NOT NULL,
 size ENUM('S','M','L') NOT NULL,
 temperature ENUM('HOT','ICED','NA') NOT NULL,
 sku VARCHAR(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
 price DECIMAL(12,2) NOT NULL,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY variant_choice(product_id,size,temperature),
 FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ingredients (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 unit ENUM('g','mL','units') NOT NULL,
 minimum_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
 current_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
 opened_shelf_life_hours INT UNSIGNED NULL,
 is_active TINYINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS recipes (
 variant_id INT UNSIGNED NOT NULL,
 ingredient_id INT UNSIGNED NOT NULL,
 qty DECIMAL(12,3) NOT NULL,
 PRIMARY KEY(variant_id,ingredient_id),
 FOREIGN KEY(variant_id) REFERENCES product_variants(id),
 FOREIGN KEY(ingredient_id) REFERENCES ingredients(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_batches (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ingredient_id INT UNSIGNED NOT NULL,
 delivery_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reference VARCHAR(100) NOT NULL,
 supplier VARCHAR(100) NULL,
 lot_reference VARCHAR(100) NULL,
 expiration_date DATE NULL,
 received_qty DECIMAL(12,3) NOT NULL,
 remaining_qty DECIMAL(12,3) NOT NULL,
 qty_sealed DECIMAL(12,3) NOT NULL,
 qty_opened DECIMAL(12,3) NOT NULL DEFAULT 0,
 quarantined TINYINT UNSIGNED NOT NULL DEFAULT 0,
 user_id INT UNSIGNED NOT NULL,
 received_at DATETIME NOT NULL,
 FOREIGN KEY(ingredient_id) REFERENCES ingredients(id),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_containers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_id INT UNSIGNED NOT NULL,
 received_qty DECIMAL(12,3) NOT NULL,
 remaining_qty DECIMAL(12,3) NOT NULL,
 opened_at DATETIME NULL,
 use_by DATETIME NULL,
 INDEX container_batch(batch_id),
 FOREIGN KEY(batch_id) REFERENCES stock_batches(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sales (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 transaction_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 sale_at DATETIME NOT NULL,
 cash_received DECIMAL(12,2) NOT NULL,
 change_due DECIMAL(12,2) NOT NULL,
 total_gross DECIMAL(12,2) NOT NULL,
 total_net DECIMAL(12,2) NOT NULL,
 total_vat DECIMAL(12,2) NOT NULL,
 vat_rate DECIMAL(5,4) NOT NULL,
 status ENUM('completed', 'voided') NOT NULL DEFAULT 'completed',
 void_reason VARCHAR(255) NULL DEFAULT NULL,
 voided_by INT UNSIGNED NULL DEFAULT NULL,
 voided_at DATETIME NULL DEFAULT NULL,
 INDEX sale_date(sale_at),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(voided_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sale_items (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sale_id INT UNSIGNED NOT NULL,
 variant_id INT UNSIGNED NOT NULL,
 variant_label VARCHAR(180) NOT NULL,
 qty INT UNSIGNED NOT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 line_gross DECIMAL(12,2) NOT NULL,
 line_net DECIMAL(12,2) NOT NULL,
 line_vat DECIMAL(12,2) NOT NULL,
 INDEX sale_lines(sale_id),
 FOREIGN KEY(sale_id) REFERENCES sales(id),
 FOREIGN KEY(variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ingredient_id INT UNSIGNED NOT NULL,
 batch_id INT UNSIGNED NOT NULL,
 container_id INT UNSIGNED NOT NULL,
 sale_id INT UNSIGNED NULL,
 user_id INT UNSIGNED NOT NULL,
 type ENUM('RECEIVE','SALE','WASTE','ADJUSTMENT') NOT NULL,
 quantity_delta DECIMAL(12,3) NOT NULL,
 reason VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX movement_ingredient(ingredient_id),
 FOREIGN KEY(ingredient_id) REFERENCES ingredients(id),
 FOREIGN KEY(batch_id) REFERENCES stock_batches(id),
 FOREIGN KEY(container_id) REFERENCES stock_containers(id),
 FOREIGN KEY(sale_id) REFERENCES sales(id),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ==========================================
-- 003_reliability.sql
-- ==========================================
-- Stage 3 durable rejection outcomes and offline owner recovery.
CREATE TABLE IF NOT EXISTS checkout_rejections (
 transaction_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 status_code SMALLINT UNSIGNED NOT NULL,
 error_code VARCHAR(64) NOT NULL,
 message VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS owner_recovery (
 user_id INT UNSIGNED PRIMARY KEY,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generated_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ==========================================
-- 004_management.sql
-- ==========================================
CREATE TABLE IF NOT EXISTS runtime_meta (
 id TINYINT UNSIGNED PRIMARY KEY,
 epoch CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 restore_generation INT UNSIGNED NOT NULL DEFAULT 0,
 restored_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_operations (
 operation_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 result_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_details (
 audit_id BIGINT UNSIGNED PRIMARY KEY,
 details LONGTEXT NOT NULL,
 FOREIGN KEY(audit_id) REFERENCES audit_events(id)
) ENGINE=InnoDB;

-- ==========================================
-- 005_void_and_images.sql
-- ==========================================
-- Upgrade: Owner order voiding and optional product images.
ALTER TABLE products ADD COLUMN IF NOT EXISTS image_filename VARCHAR(255) NULL DEFAULT NULL AFTER category;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS status ENUM('completed', 'voided') NOT NULL DEFAULT 'completed' AFTER vat_rate;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL DEFAULT NULL AFTER status;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS voided_by INT UNSIGNED NULL DEFAULT NULL AFTER void_reason;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL DEFAULT NULL AFTER voided_by;

-- ==========================================
-- 006_cloud_auth.sql
-- ==========================================
-- Stage 6 Cloud Web Onboarding & Email Password Recovery schema additions.
ALTER TABLE users
 ADD COLUMN IF NOT EXISTS email VARCHAR(191) CHARACTER SET ascii COLLATE ascii_general_ci NULL DEFAULT NULL AFTER display_name,
 ADD COLUMN IF NOT EXISTS shop_name VARCHAR(120) NULL DEFAULT NULL AFTER email;

CREATE TABLE IF NOT EXISTS password_resets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL DEFAULT NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_reset_user(user_id),
 INDEX idx_reset_expiry(expires_at),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    transaction_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('PENDING', 'IN_FLIGHT', 'SYNCED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME NULL DEFAULT NULL,
    INDEX idx_outbox_queue (status, created_at),
    INDEX idx_outbox_key (transaction_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_inbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    entity_type VARCHAR(32) NOT NULL,
    source_shop_id VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('APPLIED', 'SKIPPED_DUPLICATE', 'ERROR') NOT NULL DEFAULT 'APPLIED',
    error_log TEXT NULL DEFAULT NULL,
    INDEX idx_inbox_time (received_at),
    INDEX idx_inbox_type (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_meta (
    meta_key VARCHAR(64) PRIMARY KEY,
    meta_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================
-- System Metadata Initialization (No users/sales/inventory)
-- ==========================================
INSERT INTO app_state (id, schema_version, installed_at) VALUES (1, 4, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE schema_version = 4;
INSERT INTO runtime_meta (id, epoch, restore_generation, restored_at) VALUES (1, 'b0d5ab9a9a741ab161b8207e688bc4b7', 0, NULL) ON DUPLICATE KEY UPDATE id = 1;
INSERT IGNORE INTO sync_meta (meta_key, meta_value) VALUES
('shop_id', 'nearbycafe_intramuros_01'),
('shop_name', 'Nearby Café - Legaspi St. Intramuros'),
('last_sync_attempt_at', '1970-01-01 00:00:00'),
('last_successful_sync_at', '1970-01-01 00:00:00'),
('sync_status', 'OFFLINE'),
('consecutive_failures', '0'),
('config_version', '1');

SET FOREIGN_KEY_CHECKS = 1;
