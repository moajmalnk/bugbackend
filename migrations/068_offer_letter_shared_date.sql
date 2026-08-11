-- =============================================================================
-- BugRicer Migration 068 — Offer letter shared date (admin HR)
-- =============================================================================
-- When HR marks offer letter as issued, record the date it was shared.
-- Safe to re-run.
-- =============================================================================

SET @db := DATABASE();

SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'offer_letter_shared_date'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `offer_letter_shared_date` DATE NULL DEFAULT NULL COMMENT ''Date offer letter was shared with the employee'' AFTER `offer_letter_issued`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
