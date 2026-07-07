ALTER TABLE backup_jobs
  ADD COLUMN mail_status ENUM('pending', 'sent', 'error_sent', 'failed') NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN mail_error TEXT NULL AFTER mail_status;
