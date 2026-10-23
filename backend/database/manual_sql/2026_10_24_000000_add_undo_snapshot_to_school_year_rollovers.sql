/* Enter "USE {database};" to start exploring your data.
   Adds the retained before-state and five-minute undo window for academic rollovers. */
USE gatrh;

-- ADD ROLLOVER UNDO FIELDS ---------------------------------
ALTER TABLE school_year_rollovers
  DROP INDEX school_year_rollovers_school_year_unique,
  ADD INDEX school_year_rollovers_school_year_index (school_year),
  ADD COLUMN snapshot JSON NULL,
  ADD COLUMN undo_expires_at TIMESTAMP NULL,
  ADD COLUMN undone_at TIMESTAMP NULL;

-- INSERT MIGRATION -----------------------------------------
INSERT INTO migrations (migration, batch)
SELECT
  '2026_10_24_000000_add_undo_snapshot_to_school_year_rollovers',
  (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations)
WHERE NOT EXISTS (
  SELECT 1
  FROM migrations
  WHERE migration = '2026_10_24_000000_add_undo_snapshot_to_school_year_rollovers'
);
