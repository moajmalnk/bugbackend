-- When an update is approved / declined / completed, and completion checklist when marking complete
ALTER TABLE `updates` ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `declined_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `completion_tested` TINYINT(1) NULL DEFAULT NULL COMMENT '1=tested, 0=not tested';
ALTER TABLE `updates` ADD COLUMN `completion_dev_hours` DECIMAL(10,2) NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `completion_dev_started_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `completion_dev_ended_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `completion_tested_by` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `updates` ADD COLUMN `completion_notes` TEXT NULL;
