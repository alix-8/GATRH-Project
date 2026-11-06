-- DESTRUCTIVE: permanently deletes application data in the selected TiDB database.
-- Back up the database first and confirm the database selector says `gatrh`.
-- This keeps the schema, `migrations`, `system_settings`, and `gathering_schedules`.
-- It resets the academic year to 2026-2027. Run Laravel's DatabaseSeeder afterward.

USE gatrh;

START TRANSACTION;

-- Remove data that references users, students, programs, or reminders first.
DELETE FROM attendance_records;
DELETE FROM escalations;
DELETE FROM attendance_drafts;
DELETE FROM monthly_export_tasks;
DELETE FROM reminders;
DELETE FROM audit_logs;
DELETE FROM school_year_rollovers;
DELETE FROM personal_access_tokens;
DELETE FROM program_user;
DELETE FROM sessions;
DELETE FROM password_reset_tokens;

-- Clear queues and cached application state.
DELETE FROM failed_jobs;
DELETE FROM job_batches;
DELETE FROM jobs;
DELETE FROM cache_locks;
DELETE FROM cache;

-- Keep system configuration but restore the original school year.
UPDATE system_settings
SET academic_year = '2026-2027',
    updated_by = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE id = 1;

-- Remove seeded entities; Laravel's seeder will recreate them.
DELETE FROM students;
DELETE FROM users;
DELETE FROM programs;

COMMIT;

-- Verify the reset before running Laravel's seeder.
SELECT id, academic_year FROM system_settings;
SELECT COUNT(*) AS users_remaining FROM users;
SELECT COUNT(*) AS students_remaining FROM students;
SELECT COUNT(*) AS programs_remaining FROM programs;
SELECT COUNT(*) AS rollovers_remaining FROM school_year_rollovers;
