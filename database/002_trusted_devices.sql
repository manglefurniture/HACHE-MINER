-- HACHE-MINER additive migration: private remembered browser tokens only.
-- Store only SHA-256 of validator; never passwords or bearer token plaintext.
CREATE TABLE IF NOT EXISTS trusted_devices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL,
 selector CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 device_label VARCHAR(120) NOT NULL DEFAULT 'Navegador',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_used_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 INDEX idx_admin_devices (admin_id, expires_at),
 CONSTRAINT fk_trusted_admin FOREIGN KEY (admin_id) REFERENCES administrators(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
