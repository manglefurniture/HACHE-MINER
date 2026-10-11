-- Official SaladCloud price observations. Separate from legacy manually entered gpu_rates.
-- No historical sample is rewritten; provenance is always the authenticated organization catalog.
CREATE TABLE IF NOT EXISTS salad_gpu_prices (
 organization VARCHAR(64) NOT NULL,
 gpu_class VARCHAR(120) NOT NULL,
 priority VARCHAR(30) NOT NULL,
 usd_per_hour DECIMAL(12,6) NOT NULL,
 observed_at DATETIME NOT NULL,
 PRIMARY KEY (organization,gpu_class,priority),
 KEY idx_price_freshness (observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS salad_gpu_price_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization VARCHAR(64) NOT NULL,
 gpu_class VARCHAR(120) NOT NULL,
 priority VARCHAR(30) NOT NULL,
 usd_per_hour DECIMAL(12,6) NOT NULL,
 observed_at DATETIME NOT NULL,
 KEY idx_price_history (organization,gpu_class,priority,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Capture the effective class, priority and verified USD/h on EACH new sample.
-- Older rows remain NULL/unknown; their manual cost estimate is never reclassified.
ALTER TABLE miner_observations
 ADD COLUMN IF NOT EXISTS gpu_class_at_observation VARCHAR(120) NULL,
 ADD COLUMN IF NOT EXISTS priority_at_observation VARCHAR(30) NULL,
 ADD COLUMN IF NOT EXISTS official_rate_usd_per_hour DECIMAL(12,6) NULL;
