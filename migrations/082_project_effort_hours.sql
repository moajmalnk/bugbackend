-- Project effort: estimated hours needed and developer hours taken (manual entry).

ALTER TABLE `projects`
  ADD COLUMN `estimated_hours` DECIMAL(8,1) DEFAULT NULL
    AFTER `developer_compliance_complete_date`,
  ADD COLUMN `developer_hours_taken` DECIMAL(8,1) DEFAULT NULL
    AFTER `estimated_hours`;
