-- =============================================================================
-- BugRicer — one-shot: mark Pay Verify Paid for all users through 2026-08-31
-- =============================================================================
-- Safe to re-run. Does NOT touch September 2026 onward (year_month >= 2026-09
-- or week_end > 2026-08-31).
--
-- Fixes #1267 collation mix: users is often utf8mb4_general_ci while pay-verify
-- tables are utf8mb4_unicode_ci — all joins/filters cast explicitly.
--
-- Run in phpMyAdmin / mysql client against the BugRicer database.
-- =============================================================================

SET @cutoff_date := '2026-08-31';
SET @cutoff_ym   := '2026-08';
SET @note        := 'Backfill: verified / paid through 2026-08-31';

-- Optional: first admin id for audit (NULL is fine)
SET @admin_id := (
  SELECT id FROM users
  WHERE LOWER(TRIM(COALESCE(role, ''))) COLLATE utf8mb4_unicode_ci = 'admin'
  ORDER BY created_at ASC
  LIMIT 1
);

-- -----------------------------------------------------------------------------
-- 1) Existing weeks ending on/before Aug 31 → employee verified + admin approved
-- -----------------------------------------------------------------------------
UPDATE `attendance_week_verifications`
SET
  `employee_status` = 'verified',
  `employee_verified_at` = COALESCE(`employee_verified_at`, NOW()),
  `employee_note` = COALESCE(NULLIF(`employee_note`, ''), @note),
  `admin_status` = 'approved',
  `admin_verified_at` = COALESCE(`admin_verified_at`, NOW()),
  `admin_id` = COALESCE(`admin_id`, @admin_id),
  `admin_note` = COALESCE(NULLIF(`admin_note`, ''), @note),
  `snapshot_locked` = 1,
  `updated_at` = CURRENT_TIMESTAMP
WHERE `week_end` <= @cutoff_date;

-- -----------------------------------------------------------------------------
-- 2) Existing months Jan..Aug 2026 (and earlier) → Paid
-- -----------------------------------------------------------------------------
UPDATE `attendance_month_verifications`
SET
  `employee_status` = 'verified',
  `employee_verified_at` = COALESCE(`employee_verified_at`, NOW()),
  `employee_note` = COALESCE(NULLIF(`employee_note`, ''), @note),
  `admin_status` = 'approved',
  `admin_verified_at` = COALESCE(`admin_verified_at`, NOW()),
  `admin_id` = COALESCE(`admin_id`, @admin_id),
  `admin_note` = COALESCE(NULLIF(`admin_note`, ''), @note),
  `updated_at` = CURRENT_TIMESTAMP
WHERE `year_month` <= @cutoff_ym;

-- -----------------------------------------------------------------------------
-- 3) Insert missing calendar months (2025-01 .. 2026-08) for workforce users
--    so opening Pay Verify does not recreate Pending months.
-- -----------------------------------------------------------------------------
INSERT INTO `attendance_month_verifications`
  (`id`, `user_id`, `year_month`, `period_start`, `period_end`,
   `total_hours`, `worked_days`, `leave_days`, `leave_hours`, `ot_hours`, `check_in_days`,
   `hourly_rate_used`, `gross_estimate`, `adjustments_total`, `net_estimate`, `include_ot`,
   `employee_status`, `employee_note`, `employee_verified_at`,
   `admin_status`, `admin_note`, `admin_verified_at`, `admin_id`)
SELECT
  UUID(),
  CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci,
  CONVERT(ym.ym USING utf8mb4) COLLATE utf8mb4_unicode_ci,
  DATE(CONCAT(ym.ym, '-01')),
  LAST_DAY(DATE(CONCAT(ym.ym, '-01'))),
  0, 0, 0, 0, 0, 0,
  NULL, 0, 0, 0, 0,
  'verified', @note, NOW(),
  'approved', @note, NOW(),
  CONVERT(@admin_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
FROM users u
CROSS JOIN (
  SELECT DATE_FORMAT(DATE_ADD('2025-01-01', INTERVAL seq MONTH), '%Y-%m') AS ym
  FROM (
    SELECT a.N + b.N * 10 AS seq
    FROM
      (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
       UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
      (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2) b
  ) nums
  WHERE DATE_ADD('2025-01-01', INTERVAL seq MONTH) <= '2026-08-01'
) ym
WHERE LOWER(TRIM(COALESCE(u.role, ''))) COLLATE utf8mb4_unicode_ci
      IN ('developer', 'user', 'creator', 'tester')
  AND NOT EXISTS (
    SELECT 1
    FROM `attendance_month_verifications` m
    WHERE m.user_id = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
      AND m.`year_month` = CONVERT(ym.ym USING utf8mb4) COLLATE utf8mb4_unicode_ci
  );

-- -----------------------------------------------------------------------------
-- 4) Insert missing Mon–Sat weeks with week_end <= 2026-08-31 for workforce
--    (empty hours; opening Pay Verify will refresh hours while status stays locked)
-- -----------------------------------------------------------------------------
INSERT INTO `attendance_week_verifications`
  (`id`, `user_id`, `week_start`, `week_end`, `year_month`,
   `worked_hours`, `leave_hours`, `ot_hours`, `check_in_days`, `leave_days`,
   `office_days`, `wfh_days`, `late_days`, `days_worked`,
   `employee_status`, `employee_note`, `employee_verified_at`,
   `admin_status`, `admin_note`, `admin_verified_at`, `admin_id`, `snapshot_locked`)
SELECT
  UUID(),
  CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci,
  w.week_start,
  w.week_end,
  CONVERT(DATE_FORMAT(w.week_start, '%Y-%m') USING utf8mb4) COLLATE utf8mb4_unicode_ci,
  0, 0, 0, 0, 0, 0, 0, 0, 0,
  'verified', @note, NOW(),
  'approved', @note, NOW(),
  CONVERT(@admin_id USING utf8mb4) COLLATE utf8mb4_unicode_ci,
  1
FROM users u
CROSS JOIN (
  SELECT
    d.d AS week_start,
    DATE_ADD(d.d, INTERVAL 5 DAY) AS week_end
  FROM (
    SELECT DATE_ADD('2025-01-06', INTERVAL seq WEEK) AS d
    FROM (
      SELECT a.N + b.N * 10 + c.N * 100 AS seq
      FROM
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
         UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
         UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
        (SELECT 0 AS N UNION SELECT 1) c
    ) nums
    WHERE DATE_ADD('2025-01-06', INTERVAL seq WEEK) <= @cutoff_date
  ) d
  WHERE DATE_ADD(d.d, INTERVAL 5 DAY) <= @cutoff_date
) w
WHERE LOWER(TRIM(COALESCE(u.role, ''))) COLLATE utf8mb4_unicode_ci
      IN ('developer', 'user', 'creator', 'tester')
  AND NOT EXISTS (
    SELECT 1
    FROM `attendance_week_verifications` wv
    WHERE wv.user_id = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
      AND wv.week_start = w.week_start
  );

-- -----------------------------------------------------------------------------
-- 5) Sanity checks
-- -----------------------------------------------------------------------------
SELECT 'weeks_pending_through_aug' AS check_name, COUNT(*) AS cnt
FROM `attendance_week_verifications`
WHERE `week_end` <= @cutoff_date
  AND (`employee_status` <> 'verified' OR `admin_status` <> 'approved')
UNION ALL
SELECT 'months_pending_through_aug', COUNT(*)
FROM `attendance_month_verifications`
WHERE `year_month` <= @cutoff_ym
  AND (`employee_status` <> 'verified' OR `admin_status` <> 'approved')
UNION ALL
SELECT 'months_paid_through_aug', COUNT(*)
FROM `attendance_month_verifications`
WHERE `year_month` <= @cutoff_ym AND `admin_status` = 'approved'
UNION ALL
SELECT 'weeks_locked_through_aug', COUNT(*)
FROM `attendance_week_verifications`
WHERE `week_end` <= @cutoff_date AND `admin_status` = 'approved';
