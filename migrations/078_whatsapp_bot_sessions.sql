-- ============================================================
-- BugRicer Migration 078 — WhatsApp Bot Sessions & Schema Alignment
-- ============================================================
-- Adds: wa_sessions, wa_submission_attachments_temp
-- Ensures: users.phone unique index, is_wa_verified, wa_verified_at
--           bugs.source supports 'whatsapp', bugs.audio_note_url
-- Safe to re-run (guards with IF NOT EXISTS / SHOW COLUMNS).
-- ============================================================

SET @db := DATABASE();

-- ----------------------------------------------------------------
-- 1. wa_sessions — per-phone conversation state for the WA bot
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_sessions` (
  `phone`                VARCHAR(30)  NOT NULL,
  `user_id`              VARCHAR(36)  NULL,
  `current_step`         ENUM(
                            'IDLE',
                            'WAITING_OTP',
                            'SELECT_PROJECT',
                            'AWAITING_BUG_CONTENT',
                            'CONFIRM_SUBMISSION'
                          ) NOT NULL DEFAULT 'IDLE',
  `selected_project_id`  VARCHAR(36)  NULL,
  `otp_code`             VARCHAR(6)   NULL,
  `otp_expires_at`       DATETIME     NULL,
  `otp_attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `otp_first_attempt_at` DATETIME     NULL  COMMENT 'Window start for rate-limit (max 3 in 10 min)',
  `temp_title`           VARCHAR(255) NULL,
  `temp_description`     TEXT         NULL,
  `last_interaction`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`),
  KEY `idx_wa_sessions_user`    (`user_id`),
  KEY `idx_wa_sessions_step`    (`current_step`),
  KEY `idx_wa_sessions_touched` (`last_interaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='WhatsApp bot per-phone state machine';

-- ----------------------------------------------------------------
-- 2. wa_submission_attachments_temp — multi-attachment staging area
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_submission_attachments_temp` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`      VARCHAR(30)  NOT NULL,
  `file_path`  VARCHAR(500) NOT NULL  COMMENT 'Relative to uploads/ root, e.g. wa_staging/xxx.ogg',
  `file_name`  VARCHAR(255) NOT NULL,
  `file_type`  VARCHAR(100) NOT NULL  COMMENT 'MIME type',
  `duration`   INT          NULL      COMMENT 'Seconds (for audio/video)',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_att_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Temporary staging for WhatsApp bot attachments before bug submission';

-- ----------------------------------------------------------------
-- 3. users.phone — ensure unique index exists
-- ----------------------------------------------------------------
SET @phone_idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME   = 'users'
    AND INDEX_NAME   = 'uniq_users_phone'
);
SET @sql := IF(@phone_idx_exists = 0,
  'ALTER TABLE `users` ADD UNIQUE INDEX `uniq_users_phone` (`phone`(20))',
  'SELECT 1 -- phone unique index already exists'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------
-- 4. users.is_wa_verified
-- ----------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_wa_verified'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `is_wa_verified` TINYINT(1) NOT NULL DEFAULT 0
   COMMENT ''1 = verified via WhatsApp OTP'' AFTER `phone`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------
-- 5. users.wa_verified_at
-- ----------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'wa_verified_at'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `users` ADD COLUMN `wa_verified_at` DATETIME NULL DEFAULT NULL
   COMMENT ''Timestamp of first successful WA OTP verification'' AFTER `is_wa_verified`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------
-- 6. bugs.source — extend ENUM to include ''whatsapp'' and ''api''
--    Strategy: modify the ENUM safely using MODIFY COLUMN.
--    If the column is NOT an ENUM (unlikely), fall back to VARCHAR.
-- ----------------------------------------------------------------
SET @col_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'source'
);

-- Only run if the column already exists
SET @source_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'source'
);

-- Add column if missing (as VARCHAR so it works regardless of prior type)
SET @sql := IF(@source_exists = 0,
  "ALTER TABLE `bugs` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'web'
   COMMENT 'Ingestion channel: web | whatsapp | api | bugbot' AFTER `id`",
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- If it exists as ENUM, attempt to widen it (ignore on error)
SET @is_enum := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'source'
    AND COLUMN_TYPE LIKE 'enum(%'
);
-- Widen to include 'whatsapp'; guard with IF so it's a no-op if already wide
SET @has_wa := IF(@is_enum > 0, (
  SELECT IF(LOCATE('''whatsapp''', COLUMN_TYPE) > 0, 1, 0)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'source'
), 1);
SET @sql := IF(@is_enum > 0 AND @has_wa = 0,
  "ALTER TABLE `bugs` MODIFY COLUMN `source`
     ENUM('web','whatsapp','api','bugbot') NOT NULL DEFAULT 'web'",
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add index on source for fast channel filtering
SET @src_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND INDEX_NAME = 'idx_bugs_source'
);
SET @sql := IF(@src_idx = 0,
  'CREATE INDEX `idx_bugs_source` ON `bugs` (`source`)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ----------------------------------------------------------------
-- 7. bugs.audio_note_url
-- ----------------------------------------------------------------
SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'bugs' AND COLUMN_NAME = 'audio_note_url'
);
SET @sql := IF(@exist = 0,
  'ALTER TABLE `bugs` ADD COLUMN `audio_note_url` VARCHAR(500) NULL DEFAULT NULL
   COMMENT ''Relative path of primary WhatsApp audio/voice note attachment'' AFTER `source`',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
