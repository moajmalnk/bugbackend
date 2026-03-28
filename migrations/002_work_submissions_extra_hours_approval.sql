-- Extra-hour approval workflow (admin approve / reject / change approved amount)
ALTER TABLE work_submissions
  ADD COLUMN extra_hours_approval_status VARCHAR(24) NOT NULL DEFAULT 'none'
    COMMENT 'none|pending|approved|rejected|changed'
    AFTER approval_reason;

ALTER TABLE work_submissions
  ADD COLUMN extra_hours_approved_amount DECIMAL(6,2) NULL DEFAULT NULL
    AFTER extra_hours_approval_status;

ALTER TABLE work_submissions
  ADD COLUMN extra_hours_reviewed_by INT UNSIGNED NULL DEFAULT NULL
    AFTER extra_hours_approved_amount;

ALTER TABLE work_submissions
  ADD COLUMN extra_hours_reviewed_at DATETIME NULL DEFAULT NULL
    AFTER extra_hours_reviewed_by;

ALTER TABLE work_submissions
  ADD COLUMN extra_hours_admin_note TEXT NULL DEFAULT NULL
    AFTER extra_hours_reviewed_at;

-- Optional: normalize legacy rows (otherwise `none` + request still counts OT until user resaves)
-- UPDATE work_submissions SET extra_hours_approval_status = 'approved', extra_hours_approved_amount = overtime_hours
-- WHERE (COALESCE(requested_extra_hours,0) > 0 OR TRIM(COALESCE(approval_reason,'')) <> '') AND extra_hours_approval_status = 'none';
