-- BugRicer — Cleanup after merge_user_accounts.sql
-- Why: rows left on the FROM account after a merge are exact duplicates of rows the TO
-- account already has (same notification, same rule ack, same group, same date), blocked by
-- unique keys. They are safe to delete. Run ONLY after the merge reported APPLIED.

SET @from_user_id := '0fafc182-237a-4414-932a-175ed9c24db8';

START TRANSACTION;
DELETE FROM user_notifications WHERE BINARY user_id = @from_user_id;
DELETE FROM codo_rule_acknowledgements WHERE BINARY user_id = @from_user_id;
DELETE FROM chat_group_members WHERE BINARY user_id = @from_user_id;
DELETE FROM attendance_day_exceptions WHERE BINARY user_id = @from_user_id;
DELETE FROM attendance_wfh_requests WHERE BINARY user_id = @from_user_id;
DELETE FROM user_feedback_tracking WHERE BINARY user_id = @from_user_id;
COMMIT;

SELECT
  (SELECT COUNT(*) FROM bugs WHERE BINARY reported_by = @from_user_id) AS bugs_left_on_from,
  (SELECT COUNT(*) FROM bugs WHERE BINARY reported_by = 'f78c67de-30dc-4829-9334-d9be76511828') AS bugs_on_to,
  (SELECT COUNT(*) FROM user_notifications WHERE BINARY user_id = @from_user_id) AS notifs_left_on_from,
  (SELECT account_active FROM users WHERE BINARY id = @from_user_id) AS from_account_active;
