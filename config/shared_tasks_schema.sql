-- Shared Tasks Schema
CREATE TABLE IF NOT EXISTS shared_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    created_by VARCHAR(36) NOT NULL,
    assigned_to VARCHAR(36) NOT NULL,
    approved_by VARCHAR(36) DEFAULT NULL,
    completed_by VARCHAR(36) DEFAULT NULL,
    project_id VARCHAR(36) DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'approved') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_by (created_by),
    INDEX idx_status (status),
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table for shared tasks with multiple projects
CREATE TABLE IF NOT EXISTS shared_task_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shared_task_id INT NOT NULL,
    project_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shared_task_id) REFERENCES shared_tasks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_task_project (shared_task_id, project_id),
    INDEX idx_shared_task_id (shared_task_id),
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

