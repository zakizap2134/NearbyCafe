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
