-- Preview + soft-delete forgot-checkout ADMIN HOURS entries for 2026-08-25 and 2026-08-26.
-- Prefer using Official Leave UI with "Remove forgot-checkout admin hours" checked —
-- this SQL is for manual DB cleanup / audit.

-- 1) PREVIEW (run first)
SELECT
  ws.id,
  u.username,
  ws.user_id,
  ws.submission_date,
  ws.hours_today,
  LEFT(ws.notes, 160) AS notes_preview,
  ws.deleted_at
FROM work_submissions ws
LEFT JOIN users u ON u.id = ws.user_id
WHERE ws.submission_date IN ('2026-08-25', '2026-08-26')
  AND ws.notes LIKE '%[ADMIN HOURS ENTRY%'
  AND (ws.deleted_at IS NULL OR ws.deleted_at = ws.deleted_at)
ORDER BY ws.submission_date, u.username;

-- Live-only preview (preferred if deleted_at exists):
-- AND ws.deleted_at IS NULL

-- 2) SOFT-DELETE into recycle-bin style (keeps row, hides from dashboards)
-- Replace ADMIN_USER_ID with your admin users.id UUID.
/*
UPDATE work_submissions
SET deleted_at = NOW(),
    deleted_by = 'ADMIN_USER_ID'
WHERE submission_date IN ('2026-08-25', '2026-08-26')
  AND notes LIKE '%[ADMIN HOURS ENTRY%'
  AND deleted_at IS NULL;
*/

-- 3) HARD DELETE (only if you do not use recycle bin / deleted_at)
/*
DELETE FROM work_submissions
WHERE submission_date IN ('2026-08-25', '2026-08-26')
  AND notes LIKE '%[ADMIN HOURS ENTRY%';
*/

-- 4) After cleanup: grant Official Leave from Admin → Official Leave
--    Start 2026-08-25 · End 2026-08-26 · Title e.g. Meelad Nabi
--    Scope: All users
--    ✅ Remove forgot-checkout admin hours
--    ✅ Notify all granted users (push + WhatsApp + email)
