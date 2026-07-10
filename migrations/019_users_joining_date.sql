-- Adds employment joining date for attendance gating.
-- If NULL, application falls back to DATE(created_at).

ALTER TABLE users
  ADD COLUMN joining_date DATE NULL
  COMMENT 'Employment start date; attendance blocked before this day'
  AFTER created_at;
