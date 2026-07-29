-- Extra bug type seeds (safe to re-run). Tables must already exist (032_bug_types.sql).

INSERT INTO bug_types (id, name, slug, is_active, sort_order)
VALUES
  ('bt-ui-issue-000000000000000001', 'UI Issue', 'ui_issue', 1, 10),
  ('bt-functional-issue-0000000002', 'Functional Issue', 'functional_issue', 1, 20),
  ('bt-logical-issue-000000000003', 'Logical Issue', 'logical_issue', 1, 30),
  ('bt-ux-issue-000000000000000004', 'UX Issue', 'ux_issue', 1, 40),
  ('bt-api-issue-000000000000000005', 'API Issue', 'api_issue', 1, 50),
  ('bt-performance-issue-000000006', 'Performance Issue', 'performance_issue', 1, 60),
  ('bt-security-issue-000000000007', 'Security Issue', 'security_issue', 1, 70),
  ('bt-data-issue-0000000000000008', 'Data Issue', 'data_issue', 1, 80),
  ('bt-validation-issue-0000000009', 'Validation Issue', 'validation_issue', 1, 90),
  ('bt-compatibility-issue-0000010', 'Compatibility Issue', 'compatibility_issue', 1, 100),
  ('bt-responsive-issue-0000000011', 'Responsive / Mobile', 'responsive_issue', 1, 110),
  ('bt-crash-issue-000000000000012', 'Crash / Error', 'crash_issue', 1, 120),
  ('bt-integration-issue-000000013', 'Integration Issue', 'integration_issue', 1, 130),
  ('bt-permission-issue-0000000014', 'Permission / Access', 'permission_issue', 1, 140),
  ('bt-notification-issue-00000015', 'Notification Issue', 'notification_issue', 1, 150),
  ('bt-design-mismatch-0000000016', 'Design Mismatch', 'design_mismatch', 1, 160),
  ('bt-typo-content-0000000000017', 'Typo / Content', 'typo_content', 1, 170),
  ('bt-regression-000000000000018', 'Regression', 'regression', 1, 180)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);
