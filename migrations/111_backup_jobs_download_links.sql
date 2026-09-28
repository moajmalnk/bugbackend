-- Backups are delivered as signed, expiring download links instead of email attachments
-- (multi-GB archives exceed every SMTP provider's attachment limit).
ALTER TABLE backup_jobs
  ADD COLUMN stage VARCHAR(32) NULL AFTER mail_error,
  ADD COLUMN progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER stage,
  ADD COLUMN artifacts TEXT NULL AFTER progress_percent,
  ADD COLUMN download_base VARCHAR(255) NULL AFTER artifacts,
  ADD COLUMN expires_at DATETIME NULL AFTER download_base,
  ADD INDEX idx_backup_jobs_expires_at (expires_at);
