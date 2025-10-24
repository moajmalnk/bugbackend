-- Google Sign-In Support Schema Updates (Production Safe)
-- This script safely adds the necessary fields without relying on AFTER clauses
-- It works regardless of the existing table structure

-- Add google_sub column (skip if already exists)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `google_sub` VARCHAR(255) NULL DEFAULT NULL;

-- Add profile_picture_url column (skip if already exists)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `profile_picture_url` VARCHAR(255) NULL DEFAULT NULL;

-- Add last_login_at column (skip if already exists)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL DEFAULT NULL;

-- Add unique index for google_sub (skip if already exists)
ALTER TABLE `users` ADD UNIQUE INDEX IF NOT EXISTS `idx_users_google_sub` (`google_sub`);

-- Add index for last_login_at (skip if already exists)
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_last_login_at` (`last_login_at`);

-- Update existing users to have a default last_login_at value if they have been active
UPDATE `users` 
SET `last_login_at` = `updated_at` 
WHERE `last_login_at` IS NULL AND `updated_at` > `created_at`;

-- Add comments to document the Google Sign-In fields (only modify if columns exist)
-- These will only work if the columns were successfully added above
ALTER TABLE `users` 
  MODIFY COLUMN `google_sub` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Google User ID (sub claim) from OAuth token';

ALTER TABLE `users` 
  MODIFY COLUMN `profile_picture_url` VARCHAR(255) NULL DEFAULT NULL COMMENT 'URL of user profile picture from Google';

ALTER TABLE `users` 
  MODIFY COLUMN `last_login_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp of last successful login';
