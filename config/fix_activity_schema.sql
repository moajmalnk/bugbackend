-- Fix project_activities table to allow NULL project_id
-- This is needed for activities that are not project-specific (user, feedback, meetings, messages, announcements)

ALTER TABLE project_activities MODIFY COLUMN project_id VARCHAR(36) NULL;

-- Add an index on project_id for better performance
CREATE INDEX idx_project_activities_project_id ON project_activities(project_id);

-- Add an index on activity_type for better filtering
CREATE INDEX idx_project_activities_type ON project_activities(activity_type);

-- Add an index on created_at for better sorting
CREATE INDEX idx_project_activities_created_at ON project_activities(created_at);

-- Add an index on user_id for user-specific queries
CREATE INDEX idx_project_activities_user_id ON project_activities(user_id);
