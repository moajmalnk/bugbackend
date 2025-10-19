-- ==========================================
-- Enable MySQL Event Scheduler
-- ==========================================
-- 
-- ERROR #1408 TROUBLESHOOTING:
-- If you get "Event Scheduler: An error occurred when initializing system tables"
-- This means MySQL's event tables are corrupted or missing.
--
-- SOLUTION: You DON'T need to enable Event Scheduler!
-- Use whatsapp_features_schema_no_events.sql instead.
--
-- Events are OPTIONAL - they only provide automated cleanup.
-- Your application will work perfectly without them.
-- ==========================================

-- Run these commands ONE AT A TIME in phpMyAdmin SQL tab:

-- Step 1: Check current status
SHOW VARIABLES LIKE 'event_scheduler';

-- Step 2: Try to enable (may fail with #1408 - that's OK!)
SET GLOBAL event_scheduler = 1;

-- Step 3: Verify if it worked
SHOW VARIABLES LIKE 'event_scheduler';

-- ==========================================
-- If you see error #1408, follow these steps:
-- ==========================================
--
-- OPTION 1: Ignore it and use the no-events version
-- - Just import whatsapp_features_schema_no_events.sql
-- - Everything will work fine
-- - Handle cleanup in PHP code instead
--
-- OPTION 2: Fix MySQL system tables (Advanced)
-- Run these commands in terminal (NOT phpMyAdmin):
--
-- For XAMPP on Mac:
-- cd /Applications/XAMPP/xamppfiles/bin
-- sudo ./mysql_upgrade -u root -p
-- sudo /Applications/XAMPP/xamppfiles/bin/mysql.server restart
--
-- For XAMPP on Windows (as Administrator):
-- cd C:\xampp\mysql\bin
-- mysql_upgrade.exe -u root -p
-- net stop mysql
-- net start mysql
--
-- OPTION 3: Make permanent (after fixing)
-- Edit MySQL config file:
-- - Mac: /Applications/XAMPP/xamppfiles/etc/my.cnf
-- - Windows: C:\xampp\mysql\bin\my.ini
--
-- Add under [mysqld]:
-- event_scheduler=ON
--
-- Then restart MySQL

-- ==========================================
-- RECOMMENDATION: Skip events entirely
-- ==========================================
-- Events are nice-to-have but not required.
-- Use whatsapp_features_schema_no_events.sql
-- and handle cleanup manually when needed.
