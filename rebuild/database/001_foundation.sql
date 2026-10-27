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
