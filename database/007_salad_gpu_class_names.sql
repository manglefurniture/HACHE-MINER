-- Official SaladCloud GPU class display names are required to identify
-- the class actually assigned in groups that permit several GPU classes.
CREATE TABLE IF NOT EXISTS salad_gpu_class_names (
  organization VARCHAR(64) NOT NULL,
  gpu_class VARCHAR(120) NOT NULL,
  name VARCHAR(120) NOT NULL,
  observed_at DATETIME NOT NULL,
  PRIMARY KEY (organization,gpu_class),
  KEY idx_gpu_name_freshness (observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
