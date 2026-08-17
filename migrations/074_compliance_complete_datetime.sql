-- Store compliance completing dates with a time (default 09:00).

ALTER TABLE `projects`
  MODIFY COLUMN `tester_compliance_complete_date` DATETIME DEFAULT NULL,
  MODIFY COLUMN `developer_compliance_complete_date` DATETIME DEFAULT NULL;
