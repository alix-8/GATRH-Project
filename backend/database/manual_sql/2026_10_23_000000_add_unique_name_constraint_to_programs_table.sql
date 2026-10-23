/* Enter "USE {database};" to start exploring your data.
   Check for duplicate names before adding the unique index. */
USE gatrh;

-- CHECK FOR DUPLICATE PROGRAM NAMES ------------------------
SELECT name, COUNT(*) AS duplicate_count
FROM programs
WHERE name IS NOT NULL
GROUP BY name
HAVING COUNT(*) > 1;

-- ADD UNIQUE PROGRAM NAME CONSTRAINT -----------------------
-- Run this only after the duplicate check returns no rows.
ALTER TABLE programs
ADD CONSTRAINT programs_name_unique UNIQUE (name);

-- INSERT MIGRATION -----------------------------------------
INSERT INTO migrations (migration, batch)
SELECT
  '2026_10_23_000000_add_unique_name_constraint_to_programs_table',
  (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations)
WHERE NOT EXISTS (
  SELECT 1
  FROM migrations
  WHERE migration = '2026_10_23_000000_add_unique_name_constraint_to_programs_table'
);
