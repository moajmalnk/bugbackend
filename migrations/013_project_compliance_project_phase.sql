-- Extend compliance phases to support project-level custom rules
ALTER TABLE `project_compliance_checks`
  MODIFY `phase` enum('developer','tester','project') NOT NULL;

ALTER TABLE `project_compliance_custom_rules`
  MODIFY `phase` enum('developer','tester','project') NOT NULL;
