-- Preserve the exact RFC3339 source timestamp of each verified Salad GPU log.
-- Legacy snapshots keep their history, but poll time is not a substitute for
-- an unknown source-log time in the current-reading display.
ALTER TABLE miner_observations
 ADD COLUMN IF NOT EXISTS metric_observed_at VARCHAR(40) NULL AFTER estimated_cost_usd;
