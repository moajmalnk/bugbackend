-- Create admin audit log table for tracking admin actions
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(36) NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_user_id VARCHAR(36),
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 