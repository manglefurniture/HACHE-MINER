-- Additive, provider-isolated telemetry.  Nothing here is read by Salad jobs.
CREATE TABLE IF NOT EXISTS vast_instances (
  instance_id VARCHAR(64) NOT NULL,
  machine_id VARCHAR(64) NOT NULL,
  host_id VARCHAR(64) NULL,
  label VARCHAR(120) NOT NULL,
  gpu_count TINYINT UNSIGNED NOT NULL,
  hourly_usd DECIMAL(12,6) NOT NULL,
  low_ths DECIMAL(12,3) NOT NULL DEFAULT 0,
  normal_ths DECIMAL(12,3) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vast_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  instance_id VARCHAR(64) NOT NULL,
  sample_id CHAR(36) NOT NULL,
  observed_at DATETIME NOT NULL,
  received_at DATETIME NOT NULL,
  total_ths DECIMAL(12,3) NULL,
  accepted_shares INT UNSIGNED NULL,
  rejected_shares INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vast_sample (instance_id,sample_id),
  KEY idx_vast_sample_recent (instance_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vast_gpu_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sample_id BIGINT UNSIGNED NOT NULL,
  gpu_index TINYINT UNSIGNED NOT NULL,
  gpu_model VARCHAR(120) NOT NULL,
  hashrate_ths DECIMAL(12,3) NULL,
  average_ths DECIMAL(12,3) NULL,
  temp_c TINYINT UNSIGNED NULL,
  watts DECIMAL(10,2) NULL,
  state VARCHAR(20) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vast_gpu_sample (sample_id,gpu_index),
  CONSTRAINT fk_vast_gpu_sample FOREIGN KEY (sample_id) REFERENCES vast_samples(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
