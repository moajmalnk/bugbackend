-- Compliance completing dates for tester and developer on project timeline

ALTER TABLE `projects`
  ADD COLUMN `tester_compliance_complete_date` DATE DEFAULT NULL AFTER `backend_finish_date`,
  ADD COLUMN `developer_compliance_complete_date` DATE DEFAULT NULL AFTER `tester_compliance_complete_date`;
