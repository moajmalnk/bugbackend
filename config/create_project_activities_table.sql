-- Create project_activities table for tracking project-related activities
CREATE TABLE IF NOT EXISTS project_activities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL,
    project_id VARCHAR(36) NOT NULL,
    activity_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    related_id VARCHAR(36) NULL, -- Can reference bugs, tasks, etc.
    metadata JSON NULL, -- Additional data as JSON
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_project_activities_project_id (project_id),
    INDEX idx_project_activities_user_id (user_id),
    INDEX idx_project_activities_type (activity_type),
    INDEX idx_project_activities_created_at (created_at DESC),
    INDEX idx_project_activities_related_id (related_id),
    INDEX idx_project_activities_project_created (project_id, created_at DESC),
    INDEX idx_project_activities_user_created (user_id, created_at DESC),
    
    -- Foreign key constraints
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- Insert some sample activities for testing
INSERT INTO project_activities (user_id, project_id, activity_type, description, metadata) VALUES
((SELECT id FROM users LIMIT 1), (SELECT id FROM projects LIMIT 1), 'project_created', 'Project was created', '{"initial_setup": true}'),
((SELECT id FROM users LIMIT 1), (SELECT id FROM projects LIMIT 1), 'member_added', 'New member joined the project', '{"role": "developer"}'),
((SELECT id FROM users LIMIT 1), (SELECT id FROM projects LIMIT 1), 'bug_reported', 'Bug was reported in the project', '{"severity": "medium"}'); 