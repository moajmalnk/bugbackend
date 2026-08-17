-- Collect time on all project timeline dates (default 09:00).

ALTER TABLE `projects`
  MODIFY COLUMN `start_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `deadline_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `expected_publish_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `testing_start_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `testing_end_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `frontend_finish_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `backend_finish_date` DATETIME DEFAULT NULL;
