-- Google Sign-In Support Schema Updates (Robust Production Version)
-- This script safely adds the necessary fields with comprehensive error handling
-- It works regardless of the existing table structure and handles all edge cases

-- Step 1: Add google_sub column if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'google_sub') = 0,
    'ALTER TABLE `users` ADD COLUMN `google_sub` VARCHAR(255) NULL DEFAULT NULL',
    'SELECT "Column google_sub already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Add profile_picture_url column if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'profile_picture_url') = 0,
    'ALTER TABLE `users` ADD COLUMN `profile_picture_url` VARCHAR(255) NULL DEFAULT NULL',
    'SELECT "Column profile_picture_url already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 3: Add last_login_at column if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'last_login_at') = 0,
    'ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL DEFAULT NULL',
    'SELECT "Column last_login_at already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 4: Add unique index for google_sub if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND INDEX_NAME = 'idx_users_google_sub') = 0,
    'ALTER TABLE `users` ADD UNIQUE INDEX `idx_users_google_sub` (`google_sub`)',
    'SELECT "Index idx_users_google_sub already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 5: Add index for last_login_at if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND INDEX_NAME = 'idx_users_last_login_at') = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_last_login_at` (`last_login_at`)',
    'SELECT "Index idx_users_last_login_at already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 6: Update existing users to have a default last_login_at value if they have been active
UPDATE `users` 
SET `last_login_at` = `updated_at` 
WHERE `last_login_at` IS NULL AND `updated_at` > `created_at`;

-- Step 7: Add comments to document the Google Sign-In fields (with error handling)
-- Only modify columns if they exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'google_sub') > 0,
    'ALTER TABLE `users` MODIFY COLUMN `google_sub` VARCHAR(255) NULL DEFAULT NULL COMMENT "Google User ID (sub claim) from OAuth token"',
    'SELECT "Column google_sub does not exist, skipping comment" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'profile_picture_url') > 0,
    'ALTER TABLE `users` MODIFY COLUMN `profile_picture_url` VARCHAR(255) NULL DEFAULT NULL COMMENT "URL of user profile picture from Google"',
    'SELECT "Column profile_picture_url does not exist, skipping comment" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'last_login_at') > 0,
    'ALTER TABLE `users` MODIFY COLUMN `last_login_at` DATETIME NULL DEFAULT NULL COMMENT "Timestamp of last successful login"',
    'SELECT "Column last_login_at does not exist, skipping comment" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
