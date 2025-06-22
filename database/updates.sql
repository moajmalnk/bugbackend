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