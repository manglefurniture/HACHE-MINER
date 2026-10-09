-- Provider-neutral enrollment: one Salad API key, unlimited organization/project targets.
-- Additive migration; preserves historic groups and existing encrypted credentials.
CREATE TABLE IF NOT EXISTS salad_targets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization_slug VARCHAR(64) NOT NULL,
 project_slug VARCHAR(64) NOT NULL,
 label VARCHAR(100) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_salad_target (organization_slug,project_slug),
 INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO salad_targets (organization_slug,project_slug,label,enabled)
VALUES ('hache','prl-tests','HACHE',1),
       ('interactive','default','INTERACTIVE',1);
