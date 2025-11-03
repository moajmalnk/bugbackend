-- Fix script to add 'new_update' to notifications.type ENUM
-- Run these commands in order in your MySQL/phpMyAdmin

-- Step 1: Fix any invalid type values (update them to 'new_bug')
UPDATE notifications 
SET type = 'new_bug' 
WHERE type NOT IN ('new_bug', 'status_change', 'new_update') 
   OR type IS NULL;

-- Step 2: Now alter the ENUM to include 'new_update'
ALTER TABLE notifications 
MODIFY type ENUM('new_bug', 'status_change', 'new_update') NOT NULL;

-- Verify it worked:
SHOW COLUMNS FROM notifications WHERE Field = 'type';

