-- HACHE-MINER forward-only: immutable evidence-linked cash and PRL movements.
-- Deliberately separate from reconciled_charges (Salad consumed GPU usage).
-- This table never stores seeds, private keys, wallet credentials or API tokens.
CREATE TABLE IF NOT EXISTS accounting_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 organization VARCHAR(64) NULL,
 occurred_at DATETIME NOT NULL,
 usd_amount DECIMAL(20,8) NULL,
 usd_fee DECIMAL(20,8) NULL,
 prl_amount DECIMAL(24,8) NULL,
 source_reference VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 memo VARCHAR(300) NOT NULL DEFAULT '',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_accounting_ref (source_reference),
 KEY idx_accounting_date (occurred_at,id),
 KEY idx_accounting_org_type (organization,event_type,occurred_at),
 CONSTRAINT fk_accounting_admin FOREIGN KEY (created_by) REFERENCES administrators(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
