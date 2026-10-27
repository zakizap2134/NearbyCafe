-- Stage 2 additions. IF NOT EXISTS allows resuming table creation after interruption.
CREATE TABLE IF NOT EXISTS products (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 category VARCHAR(60) NOT NULL,
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
 INDEX sale_date(sale_at),
 FOREIGN KEY(user_id) REFERENCES users(id)
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
