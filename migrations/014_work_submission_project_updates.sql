-- Per-project checkout updates: status, progress %, notes (JSON array)
ALTER TABLE work_submissions
  ADD COLUMN project_updates JSON NULL DEFAULT NULL
  COMMENT 'Per-project checkout updates keyed by project_id'
  AFTER planned_work_notes;
