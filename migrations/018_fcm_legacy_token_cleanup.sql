-- One-time cleanup after FCM token reset rollout.
-- Run in phpMyAdmin on production, or POST /api/notifications/cleanup_legacy_fcm_tokens.php as admin.

-- Remove migrated/recovered rows that are not live browser registrations
DELETE FROM user_fcm_tokens
WHERE (device_label LIKE '%Recovered%' OR device_label LIKE '%recovered%')
   OR (platform LIKE '%legacy%' OR platform LIKE '%migration%')
   OR (user_agent LIKE '%legacy%');

-- Optional full reset (uncomment only if you want every user to re-register)
-- TRUNCATE TABLE user_fcm_tokens;
-- UPDATE users SET fcm_token = NULL;

-- After cleanup, bump FCM_TOKEN_EPOCH in backend/.env (e.g. 1 -> 2)
-- so all clients clear local cache on next login.
