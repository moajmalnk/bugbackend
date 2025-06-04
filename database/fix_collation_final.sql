-- Final collation fix - drops specific foreign keys first
-- Run this entire script at once

-- Step 1: Drop foreign key constraints from project_activities table
ALTER TABLE project_activities DROP FOREIGN KEY activities_ibfk_1;

-- Try to drop other possible foreign keys (these might not exist, ignore errors)
-- ALTER TABLE project_activities DROP FOREIGN KEY activities_ibfk_2;
-- ALTER TABLE project_activities DROP FOREIGN KEY activities_ibfk_3;

-- Step 2: Now convert all tables
ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE projects CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE bugs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE project_members CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE project_activities CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Step 3: Recreate the foreign key constraint
-- (Update column names if they're different in your database)
ALTER TABLE project_activities 
ADD CONSTRAINT activities_ibfk_1 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE;

-- Add other foreign keys if needed:
-- ALTER TABLE project_activities 
-- ADD CONSTRAINT activities_ibfk_2 
-- FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE ON UPDATE CASCADE; 