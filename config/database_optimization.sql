-- USERS table
-- CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_id_role ON users(id, role);

-- PROJECTS table
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_projects_created_by ON projects(created_by);
CREATE INDEX idx_projects_name ON projects(name);

-- BUGS table
CREATE INDEX idx_bugs_status ON bugs(status);
CREATE INDEX idx_bugs_priority ON bugs(priority);
CREATE INDEX idx_bugs_project_id ON bugs(project_id);
CREATE INDEX idx_bugs_reported_by ON bugs(reported_by);
CREATE INDEX idx_bugs_created_at ON bugs(created_at);
CREATE INDEX idx_bugs_updated_at ON bugs(updated_at);
CREATE INDEX idx_bugs_project_status_created ON bugs(project_id, status, created_at);
CREATE INDEX idx_bugs_priority_status ON bugs(priority, status);

-- PROJECT_MEMBERS table
CREATE INDEX idx_project_members_user_id ON project_members(user_id);
CREATE INDEX idx_project_members_project_id ON project_members(project_id);
CREATE INDEX idx_project_members_user_project ON project_members(user_id, project_id);
CREATE INDEX idx_project_members_joined_at ON project_members(joined_at);

-- UPDATES table
CREATE INDEX idx_updates_project_id ON updates(project_id);
CREATE INDEX idx_updates_created_by ON updates(created_by);
CREATE INDEX idx_updates_type ON updates(type);
CREATE INDEX idx_updates_created_at ON updates(created_at);
CREATE INDEX idx_updates_status ON updates(status);

-- ACTIVITIES table
CREATE INDEX idx_activities_user_id ON activities(user_id);
CREATE INDEX idx_activities_type ON activities(type);
CREATE INDEX idx_activities_entity_id ON activities(entity_id);
CREATE INDEX idx_activities_created_at ON activities(created_at);

-- ACTIVITY_LOG table
CREATE INDEX idx_activity_log_user_id ON activity_log(user_id);
CREATE INDEX idx_activity_log_action_type ON activity_log(action_type);
CREATE INDEX idx_activity_log_entity_type ON activity_log(entity_type);
CREATE INDEX idx_activity_log_entity_id ON activity_log(entity_id);
CREATE INDEX idx_activity_log_created_at ON activity_log(created_at);

-- PROJECT_ACTIVITIES table
CREATE INDEX idx_project_activities_project_id ON project_activities(project_id);
CREATE INDEX idx_project_activities_user_id ON project_activities(user_id);
CREATE INDEX idx_project_activities_activity_type ON project_activities(activity_type);
CREATE INDEX idx_project_activities_created_at ON project_activities(created_at);
CREATE INDEX idx_project_activities_related_id ON project_activities(related_id);

-- BUG_ATTACHMENTS table
CREATE INDEX idx_bug_attachments_bug_id ON bug_attachments(bug_id);
CREATE INDEX idx_bug_attachments_uploaded_by ON bug_attachments(uploaded_by);
CREATE INDEX idx_bug_attachments_created_at ON bug_attachments(created_at);

-- NOTIFICATIONS table
CREATE INDEX idx_notifications_type ON notifications(type);
CREATE INDEX idx_notifications_bug_id ON notifications(bug_id);
CREATE INDEX idx_notifications_created_at ON notifications(created_at);

-- ANNOUNCEMENTS table
CREATE INDEX idx_announcements_is_active ON announcements(is_active);
CREATE INDEX idx_announcements_created_at ON announcements(created_at);

-- For fulltext search (if needed and supported)
ALTER TABLE bugs ADD FULLTEXT INDEX ft_bugs_search(title, description);
ALTER TABLE projects ADD FULLTEXT INDEX ft_projects_search(name, description);
ALTER TABLE announcements ADD FULLTEXT INDEX ft_announcements_search(title, content);
