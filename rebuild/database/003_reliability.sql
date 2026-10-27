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
