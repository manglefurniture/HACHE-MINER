-- Provider-authenticated, direct Salad miner telemetry. Additive and isolated
-- from Salad's log-derived observations and the existing Vast.ai collector.
CREATE TABLE IF NOT EXISTS salad_telemetry_instances (
  organization_slug VARCHAR(64) NOT NULL,
  project_slug VARCHAR(64) NOT NULL,
  group_name VARCHAR(120) NOT NULL,
  workload_id VARCHAR(120) NOT NULL,
  instance_id VARCHAR(120) NOT NULL,
  machine_id VARCHAR(120) NOT NULL,
  instance_state VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (organization_slug,project_slug,group_name,instance_id),
  KEY idx_salad_telemetry_identity (machine_id,workload_id,enabled,last_seen_at),
  KEY idx_salad_telemetry_group (organization_slug,project_slug,group_name,enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salad_telemetry_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_slug VARCHAR(64) NOT NULL,
  project_slug VARCHAR(64) NOT NULL,
  group_name VARCHAR(120) NOT NULL,
  instance_id VARCHAR(120) NOT NULL,
  machine_id VARCHAR(120) NOT NULL,
  sample_id CHAR(36) NOT NULL,
  observed_at DATETIME NOT NULL,
  received_at DATETIME NOT NULL,
  miner_version VARCHAR(32) NOT NULL,
  miner_state VARCHAR(20) NOT NULL,
  total_ths DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_salad_native_sample (instance_id,sample_id),
  KEY idx_salad_native_recent (instance_id,observed_at),
  KEY idx_salad_native_group (organization_slug,project_slug,group_name,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salad_telemetry_gpu_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sample_id BIGINT UNSIGNED NOT NULL,
  gpu_index TINYINT UNSIGNED NOT NULL,
  gpu_model VARCHAR(120) NOT NULL,
  hashrate_ths DECIMAL(15,4) NOT NULL,
  average_1m_ths DECIMAL(15,4) NULL,
  average_5m_ths DECIMAL(15,4) NULL,
  average_15m_ths DECIMAL(15,4) NULL,
  temp_c TINYINT UNSIGNED NULL,
  watts DECIMAL(10,2) NULL,
  accepted_shares BIGINT UNSIGNED NULL,
  rejected_shares BIGINT UNSIGNED NULL,
  hardware_errors BIGINT UNSIGNED NULL,
  state VARCHAR(20) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_salad_native_gpu (sample_id,gpu_index),
  CONSTRAINT fk_salad_native_gpu FOREIGN KEY (sample_id)
    REFERENCES salad_telemetry_samples(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
