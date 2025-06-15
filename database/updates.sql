CREATE TABLE `updates` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('feature', 'fix', 'maintenance') NOT NULL,
  `description` TEXT NOT NULL,
  `created_by` VARCHAR(36) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
);


ALTER TABLE updates ADD COLUMN status ENUM('pending', 'approved', 'declined') DEFAULT 'pending';