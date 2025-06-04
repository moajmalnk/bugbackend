-- Manual collation fix - run these commands ONE BY ONE
-- Copy and paste each command individually

-- Step 1: First, convert the main tables that others reference
ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Step 2: Convert projects table
ALTER TABLE projects CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Step 3: Convert bugs table 
ALTER TABLE bugs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Step 4: Convert project_members table
ALTER TABLE project_members CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Step 5: Finally convert project_activities (this one has foreign keys to others)
ALTER TABLE project_activities CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; 