-- ===============================================
-- PROJECT ACTIVITIES SETUP - COMPREHENSIVE
-- Works for both Local and Production environments
-- ===============================================

-- Remove any existing table to start fresh
DROP TABLE IF EXISTS project_activities;

-- Create the main project_activities table
CREATE TABLE project_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL COMMENT 'References users.id',
    project_id VARCHAR(255) NOT NULL COMMENT 'References projects.id',
    activity_type VARCHAR(50) NOT NULL COMMENT 'Type of activity (bug_reported, member_added, etc.)',
    description TEXT NOT NULL COMMENT 'Human-readable description of the activity',
    related_id VARCHAR(255) NULL COMMENT 'Optional reference to related entity (bug, task, etc.)',
    metadata TEXT NULL COMMENT 'JSON metadata for additional activity context',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the activity occurred'
);

-- Add performance indexes
CREATE INDEX pa_project_id ON project_activities (project_id);
CREATE INDEX pa_user_id ON project_activities (user_id);
CREATE INDEX pa_activity_type ON project_activities (activity_type);
CREATE INDEX pa_created_at ON project_activities (created_at DESC);
CREATE INDEX pa_related_id ON project_activities (related_id);
CREATE INDEX pa_project_created ON project_activities (project_id, created_at DESC);
CREATE INDEX pa_user_created ON project_activities (user_id, created_at DESC);

-- Insert sample activities for testing (if users and projects exist)
INSERT INTO project_activities (user_id, project_id, activity_type, description, metadata) 
SELECT 
    u.id as user_id,
    p.id as project_id,
    'project_created' as activity_type,
    'Project was created' as description,
    '{"initial_setup": true}' as metadata
FROM users u 
CROSS JOIN projects p 
LIMIT 1;

INSERT INTO project_activities (user_id, project_id, activity_type, description, metadata) 
SELECT 
    u.id as user_id,
    p.id as project_id,
    'member_added' as activity_type,
    'Initial project setup completed' as description,
    '{"role": "admin", "setup": "automated"}' as metadata
FROM users u 
CROSS JOIN projects p 
LIMIT 1;

INSERT INTO project_activities (user_id, project_id, activity_type, description, metadata) 
SELECT 
    u.id as user_id,
    p.id as project_id,
    'bug_reported' as activity_type,
    'Sample bug was reported for testing' as description,
    '{"severity": "medium", "sample": true}' as metadata
FROM users u 
CROSS JOIN projects p 
LIMIT 1;

-- Verify the setup
SELECT 'project_activities table created successfully!' as status;
SELECT COUNT(*) as sample_activities_inserted FROM project_activities;

-- Show table structure for verification
DESCRIBE project_activities;

-- Show indexes for verification
SHOW INDEX FROM project_activities;

-- ===============================================
-- SETUP COMPLETE
-- 
-- Features included:
-- ✅ Clean table creation
-- ✅ Performance indexes
-- ✅ Sample data for testing
-- ✅ Works on both local and production
-- ✅ No foreign key constraints (for compatibility)
-- ✅ Flexible metadata storage
-- =============================================== 