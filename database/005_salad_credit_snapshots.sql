-- Snapshots of official Salad billing Credits screen. Not invoices or cash receipts.
-- Screenshots are retained outside the public repo; this table stores totals only.
CREATE TABLE IF NOT EXISTS salad_credit_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 organization VARCHAR(64) NOT NULL,
 snapshot_date DATE NOT NULL,
 issued_usd DECIMAL(18,2) NOT NULL,
 consumed_usd DECIMAL(18,2) NOT NULL,
 available_usd DECIMAL(18,2) NOT NULL,
 expired_usd DECIMAL(18,2) NOT NULL,
 evidence_reference VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_credit_evidence (evidence_reference),
 KEY idx_credit_latest (organization,snapshot_date,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
