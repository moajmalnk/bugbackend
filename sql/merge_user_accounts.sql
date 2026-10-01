-- =============================================================================
-- BugRicer — Merge one user account into another (move all activity)
-- =============================================================================
-- Why: one person ended up with two accounts (e.g. a Client tester login and an
-- in-house CODO login). This moves every row that references the FROM user id
-- (bugs reported, fixes, comments, chat, project membership, attendance, leave,
-- notifications, …) onto the TO user, then deactivates the FROM account.
--
-- How it works:
--   * Discovers user-reference columns from information_schema of the live DB
--     (user_id, *_user_id, *_by, assigned_to, sender_id, reviewer_id, …), so
--     tables created lazily by the app on production are covered too.
--   * Only exact id matches are changed (`col = FROM_ID`); names/JSON untouched.
--   * Per-login identity tables are skipped (tokens, password resets,
--     onboarding/KYC, permission overrides, FCM devices, WhatsApp sessions).
--   * UPDATE IGNORE: rows that would violate a unique key (e.g. both accounts
--     already in the same project / chat group / same work day) stay on FROM
--     and are listed in the report as `remaining` for manual review.
--   * Ids are compared byte-wise (BINARY) so mixed table collations never clash.
--   * Single transaction: any SQL error rolls everything back.
--
-- Usage (phpMyAdmin → SQL tab, whole file):
--   1. EXPORT A BACKUP FIRST.
--   2. Set @from_user_id / @to_user_id below. Run with @dry_run = 1 and review.
--   3. Set @dry_run = 0 and run again.
-- =============================================================================

-- FROM = rumana_np (Client tester, source). TO = rumana (CODO tester, keeps everything).
-- dry_run: 1 = report only (nothing changes), 0 = APPLY.
SET @from_user_id := '0fafc182-237a-4414-932a-175ed9c24db8';
SET @to_user_id := 'f78c67de-30dc-4829-9334-d9be76511828';
SET @dry_run := 1;

DROP PROCEDURE IF EXISTS br_merge_user_accounts;

DELIMITER $$
CREATE PROCEDURE br_merge_user_accounts(IN p_from VARCHAR(64), IN p_to VARCHAR(64), IN p_dry_run TINYINT)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_table VARCHAR(64);
  DECLARE v_column VARCHAR(64);
  DECLARE v_cur CURSOR FOR
    SELECT c.TABLE_NAME, c.COLUMN_NAME
    FROM information_schema.COLUMNS c
    JOIN information_schema.TABLES t
      ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
    WHERE c.TABLE_SCHEMA = DATABASE()
      AND c.DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext')
      AND (
        c.COLUMN_NAME = 'user_id'
        OR c.COLUMN_NAME LIKE '%\_user\_id'
        OR c.COLUMN_NAME LIKE '%\_by'
        OR c.COLUMN_NAME IN ('assigned_to', 'reviewer_id', 'sender_id', 'creator_id',
                             'admin_id', 'employee_id', 'owner_id', 'author_id',
                             'recipient_id', 'participant_id', 'member_id', 'actor_id')
      )
      AND c.TABLE_NAME NOT IN (
        'password_resets', 'magic_links', 'google_tokens', 'user_fcm_tokens',
        'user_onboarding_details', 'user_documents', 'user_permissions',
        'wa_sessions', 'typing_indicators', 'br_merge_report'
      )
      AND NOT (c.TABLE_NAME = 'users' AND c.COLUMN_NAME = 'id')
    ORDER BY c.TABLE_NAME, c.COLUMN_NAME;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF p_from = p_to THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'from and to user ids are the same';
  END IF;
  IF (SELECT COUNT(*) FROM users WHERE BINARY id = p_from) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FROM user id not found in users';
  END IF;
  IF (SELECT COUNT(*) FROM users WHERE BINARY id = p_to) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TO user id not found in users';
  END IF;

  DROP TEMPORARY TABLE IF EXISTS br_merge_report;
  CREATE TEMPORARY TABLE br_merge_report (
    table_name VARCHAR(64),
    column_name VARCHAR(64),
    rows_found INT,
    rows_moved INT,
    remaining INT
  ) ENGINE=MEMORY;

  START TRANSACTION;

  OPEN v_cur;
  read_loop: LOOP
    FETCH v_cur INTO v_table, v_column;
    IF v_done = 1 THEN
      LEAVE read_loop;
    END IF;

    SET @sql := CONCAT('SELECT COUNT(*) INTO @found FROM `', v_table, '` WHERE BINARY `', v_column, '` = ?');
    SET @p_from := p_from;
    PREPARE s FROM @sql; EXECUTE s USING @p_from; DEALLOCATE PREPARE s;

    IF @found > 0 THEN
      SET @moved := 0;
      IF p_dry_run = 0 THEN
        SET @sql := CONCAT('UPDATE IGNORE `', v_table, '` SET `', v_column, '` = ? WHERE BINARY `', v_column, '` = ?');
        SET @p_to := p_to;
        PREPARE s FROM @sql; EXECUTE s USING @p_to, @p_from; SET @moved := ROW_COUNT(); DEALLOCATE PREPARE s;
      END IF;
      INSERT INTO br_merge_report VALUES (v_table, v_column, @found, @moved, @found - @moved);
    END IF;
  END LOOP;
  CLOSE v_cur;

  IF p_dry_run = 0 THEN
    UPDATE users SET account_active = 0 WHERE BINARY id = p_from;
    COMMIT;
  ELSE
    ROLLBACK;
  END IF;

  SELECT IF(p_dry_run = 1, 'DRY RUN — nothing changed', 'APPLIED') AS mode,
         table_name, column_name, rows_found, rows_moved, remaining
  FROM br_merge_report
  ORDER BY remaining DESC, rows_found DESC, table_name;
END$$
DELIMITER ;

CALL br_merge_user_accounts(@from_user_id, @to_user_id, @dry_run);

DROP PROCEDURE IF EXISTS br_merge_user_accounts;
