CREATE TABLE IF NOT EXISTS shared_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    -- Match production users.id definition (length + charset + collation)
    created_by VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    assigned_to VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    approved_by VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    completed_by VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    project_id VARCHAR(36) DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'approved') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Keep indexes to optimize lookups and prepare for optional FK adds later
    INDEX idx_created_by (created_by),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_approved_by (approved_by),
    INDEX idx_completed_by (completed_by),
    INDEX idx_project_id (project_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for shared tasks with multiple projects
CREATE TABLE IF NOT EXISTS shared_task_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shared_task_id INT NOT NULL,
    project_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_task_project (shared_task_id, project_id),
    INDEX idx_shared_task_id (shared_task_id),
    INDEX idx_project_id (project_id),
    CONSTRAINT fk_stp_task FOREIGN KEY (shared_task_id) REFERENCES shared_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

