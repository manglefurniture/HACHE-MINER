-- HACHE-MINER · forward-only, UTC timestamps, MariaDB 11.x
-- Apply using a dedicated least-privileged DB account; do not run against hache_natacion.
CREATE TABLE IF NOT EXISTS administrators (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(64) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(64) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 success TINYINT(1) NOT NULL,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_attempts (username, ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS secret_store (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 secret_name VARCHAR(120) NOT NULL UNIQUE,
 ciphertext TEXT NOT NULL,
 nonce VARCHAR(128) NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wallets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization VARCHAR(64) NOT NULL,
 coin VARCHAR(20) NOT NULL,
 label VARCHAR(80) NOT NULL,
 address VARCHAR(180) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_wallet (organization, coin, address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS group_state (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization VARCHAR(64) NOT NULL,
 project_name VARCHAR(64) NOT NULL,
 group_name VARCHAR(120) NOT NULL,
 state VARCHAR(50) NOT NULL,
 priority VARCHAR(30) NULL,
 desired_replicas INT UNSIGNED NOT NULL DEFAULT 0,
 gpu_class VARCHAR(120) NULL,
 last_seen_at DATETIME NOT NULL,
 UNIQUE KEY uq_group (organization,project_name,group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS miner_observations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 group_id BIGINT UNSIGNED NOT NULL,
 instance_id VARCHAR(120) NOT NULL,
 observed_at DATETIME NOT NULL,
 state VARCHAR(64) NOT NULL,
 ready TINYINT(1) NOT NULL,
 started TINYINT(1) NOT NULL,
 hashrate_ths DECIMAL(15,4) NULL,
 gpu_model VARCHAR(120) NULL,
 watts DECIMAL(10,2) NULL,
 accepted_shares BIGINT UNSIGNED NULL,
 estimated_cost_usd DECIMAL(15,8) NULL,
 FOREIGN KEY (group_id) REFERENCES group_state(id),
 UNIQUE KEY uq_observation (group_id,instance_id,observed_at),
 INDEX idx_time (observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS log_events (
 event_hash CHAR(64) PRIMARY KEY,
 group_id BIGINT UNSIGNED NOT NULL,
 logged_at DATETIME NOT NULL,
 severity VARCHAR(20) NOT NULL,
 summary VARCHAR(800) NOT NULL,
 FOREIGN KEY (group_id) REFERENCES group_state(id),
 INDEX idx_log_time (logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gpu_rates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization VARCHAR(64) NOT NULL,
 gpu_class VARCHAR(120) NOT NULL,
 priority VARCHAR(30) NOT NULL,
 usd_per_hour DECIMAL(12,6) NOT NULL,
 effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_rate (organization,gpu_class,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pool_observations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 wallet_id BIGINT UNSIGNED NOT NULL,
 observed_at DATETIME NOT NULL,
 pending_prl DECIMAL(24,8) NULL,
 confirmed_prl DECIMAL(24,8) NULL,
 hashrate_raw VARCHAR(90) NULL,
 worker_count INT UNSIGNED NULL,
 coverage_note VARCHAR(180) NOT NULL,
 FOREIGN KEY (wallet_id) REFERENCES wallets(id),
 UNIQUE KEY uq_pool (wallet_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS reconciled_charges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization VARCHAR(64) NOT NULL,
 period_start DATETIME NOT NULL,
 period_end DATETIME NOT NULL,
 amount_usd DECIMAL(12,4) NOT NULL,
 source_reference VARCHAR(200) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_charge_source (organization,source_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sync_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_name VARCHAR(120) NOT NULL,
 observed_at DATETIME NOT NULL,
 status VARCHAR(20) NOT NULL,
 detail VARCHAR(180) NOT NULL,
 INDEX idx_sync (source_name,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NULL,
 action_name VARCHAR(100) NOT NULL,
 detail VARCHAR(180) NOT NULL,
 occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
