CREATE TABLE `updates` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `project_id` VARCHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('feature', 'updation', 'maintenance') NOT NULL,
  `description` TEXT NOT NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  `status` ENUM('pending', 'approved', 'declined') DEFAULT 'pending',
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_status` (`status`),
  INDEX `idx_project_id` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE settings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  key_name VARCHAR(255) UNIQUE,
  value VARCHAR(255)
);

-- Insert default value
INSERT INTO settings (key_name, value) VALUES ('email_notifications_enabled', '1');





ALTER TABLE users
ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER password;


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


CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    expiry_date DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
); 