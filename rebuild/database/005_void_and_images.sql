-- Upgrade: Owner order voiding and optional product images.
ALTER TABLE products ADD COLUMN IF NOT EXISTS image_filename VARCHAR(255) NULL DEFAULT NULL AFTER category;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS status ENUM('completed', 'voided') NOT NULL DEFAULT 'completed' AFTER vat_rate;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL DEFAULT NULL AFTER status;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS voided_by INT UNSIGNED NULL DEFAULT NULL AFTER void_reason;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL DEFAULT NULL AFTER voided_by;
