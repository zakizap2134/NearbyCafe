-- ============================================================================
-- VENUEVUE: STORE-AND-FORWARD SYNCHRONIZATION SCHEMA
-- Project: VenueVue (Nearby Café POS & Inventory System)
-- Architecture: Local-Authoritative Master with Asynchronous Cloud Telemetry
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. LOCAL OUTBOX TABLE (Deployed on Local Shop PC)
-- Stores transactional events created during counter operations.
-- When offline, rows accumulate here with status = 'PENDING'.
-- When online, the background worker batches and flushes them to the Cloud.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('SALE', 'STOCK_MOVEMENT', 'BATCH_RECEIVE', 'WASTE', 'Z_READING') NOT NULL,
    transaction_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT NOT NULL, -- JSON formatted complete entity envelope
    status ENUM('PENDING', 'IN_FLIGHT', 'SYNCED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME NULL DEFAULT NULL,
    INDEX idx_outbox_queue (status, created_at),
    INDEX idx_outbox_key (transaction_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. CLOUD INBOX TABLE (Deployed on Online Cloud Host)
-- Ingests batches sent from the physical shop.
-- Enforces strict unique constraints on transaction_key so duplicate network
-- retries are safely ignored without corrupting financial totals.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_inbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
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

-- ----------------------------------------------------------------------------
-- 3. SYNC METADATA & TELEMETRY TABLE (Deployed on both Local and Cloud)
-- Maintains sync heartbeat timestamps, config versions, and status badges.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_meta (
    meta_key VARCHAR(64) PRIMARY KEY,
    meta_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default metadata records
INSERT INTO sync_meta (meta_key, meta_value) VALUES
('shop_id', 'nearbycafe_intramuros_01'),
('shop_name', 'Nearby Café - Legaspi St. Intramuros'),
('last_sync_attempt_at', '1970-01-01 00:00:00'),
('last_successful_sync_at', '1970-01-01 00:00:00'),
('sync_status', 'OFFLINE'),
('consecutive_failures', '0'),
('config_version', '1')
ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value);
