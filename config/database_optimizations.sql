-- Database Performance Optimizations
-- Run these commands to optimize database performance

-- Indexes for users table
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_id_role ON users(id, role);

-- Indexes for bugs table
CREATE INDEX IF NOT EXISTS idx_bugs_project_id ON bugs(project_id);
CREATE INDEX IF NOT EXISTS idx_bugs_reported_by ON bugs(reported_by);
CREATE INDEX IF NOT EXISTS idx_bugs_updated_by ON bugs(updated_by);
CREATE INDEX IF NOT EXISTS idx_bugs_status ON bugs(status);
CREATE INDEX IF NOT EXISTS idx_bugs_created_at ON bugs(created_at);
CREATE INDEX IF NOT EXISTS idx_bugs_project_created ON bugs(project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_bugs_status_updated_by ON bugs(status, updated_by);

-- Indexes for bug_attachments table
CREATE INDEX IF NOT EXISTS idx_bug_attachments_bug_id ON bug_attachments(bug_id);
CREATE INDEX IF NOT EXISTS idx_bug_attachments_uploaded_by ON bug_attachments(uploaded_by);

-- Indexes for projects table
CREATE INDEX IF NOT EXISTS idx_projects_created_by ON projects(created_by);
CREATE INDEX IF NOT EXISTS idx_projects_name ON projects(name);

-- Indexes for project_members table
CREATE INDEX IF NOT EXISTS idx_project_members_user_id ON project_members(user_id);
CREATE INDEX IF NOT EXISTS idx_project_members_project_id ON project_members(project_id);
CREATE INDEX IF NOT EXISTS idx_project_members_user_project ON project_members(user_id, project_id);
CREATE INDEX IF NOT EXISTS idx_project_members_joined_at ON project_members(joined_at);

-- Composite indexes for common query patterns
CREATE INDEX IF NOT EXISTS idx_bugs_project_status_created ON bugs(project_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_bugs_reporter_created ON bugs(reported_by, created_at);

-- Optimize table settings for better performance
ALTER TABLE users ENGINE=InnoDB;
ALTER TABLE bugs ENGINE=InnoDB;
ALTER TABLE projects ENGINE=InnoDB;
ALTER TABLE project_members ENGINE=InnoDB;
ALTER TABLE bug_attachments ENGINE=InnoDB;

-- Analyze tables for better query optimization
ANALYZE TABLE users;
ANALYZE TABLE bugs;
ANALYZE TABLE projects;
ANALYZE TABLE project_members;
ANALYZE TABLE bug_attachments;

-- Performance monitoring queries (run these to check performance)
-- SHOW PROCESSLIST;
-- SHOW ENGINE INNODB STATUS;
-- SELECT * FROM information_schema.INNODB_METRICS WHERE status='enabled';

-- Note: MySQL configuration optimizations should be done in my.cnf/my.ini file:
-- [mysqld]
-- innodb_buffer_pool_size = 128M
-- query_cache_size = 32M
-- query_cache_type = 1
-- tmp_table_size = 32M
-- max_heap_table_size = 32M 