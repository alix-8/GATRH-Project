/* Run this against the target database to add failed-action audit fields. */
USE gatrh;

ALTER TABLE audit_logs
    ADD COLUMN result VARCHAR(20) NOT NULL DEFAULT 'success',
    ADD COLUMN details TEXT NULL;

-- CHECK MIGRATION ------------------------
-- SELECT migration, batch
-- FROM migrations
-- ORDER BY migration;

-- INSERT MIGRATION ------------------------
INSERT INTO migrations (migration, batch)
SELECT
  '2026_10_25_000000_add_result_and_details_to_audit_logs_table',
  (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations)
WHERE NOT EXISTS (
  SELECT 1
  FROM migrations
  WHERE migration = '2026_10_25_000000_add_result_and_details_to_audit_logs_table'
);
