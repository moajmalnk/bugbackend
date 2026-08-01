-- Who developed the update when marking as completed
ALTER TABLE `updates`
  ADD COLUMN `completion_developed_by` VARCHAR(255) NULL DEFAULT NULL
  AFTER `completion_tested_by`;
