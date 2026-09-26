-- =============================================================================
-- BugRicer Migration 108 — Seed mailbox signed_in_from from Chrome profiles
-- Safe to re-run. Upserts by address; never clears assigned_user_id.
-- Requires: 106 (domains/emails) + 107 (signed_in_from column).
-- =============================================================================
-- Skipped Chrome nicknames (no resolvable @domain): CODO SALES, CODO APPS,
-- CODO SERVER, Work, Cake Chockers, Dmac Studio, Inmark, LUNA, Mabrook, etc.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = 'utf8mb4_unicode_ci';

-- Guard: signed_in_from must exist
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'assets_emails'
    AND COLUMN_NAME = 'signed_in_from'
);
SET @sql := IF(@has_col = 0,
  'SELECT ''Run migration 107_assets_emails_signed_in_from.sql first'' AS error',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

DROP TEMPORARY TABLE IF EXISTS tmp_mail_seed;
CREATE TEMPORARY TABLE tmp_mail_seed (
  address VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  apex VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  quota_mb INT UNSIGNED NULL,
  expires_at DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  signed_in_from VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_mail_seed
  (address, apex, quota_mb, expires_at, auto_renew, status, signed_in_from, notes)
VALUES
-- Hostinger Free / Starter inventory (from 106) + Chrome profile labels
('info@pvkenterprises.com', 'pvkenterprises.com', 102400, '2028-07-11', 0, 'active',
 'inbox.pvk', 'Chrome: inbox.pvk / inbox.pvkenterprises'),
('malmajed@darna-app.com', 'darna-app.com', 102400, '2028-07-11', 0, 'active',
 'inbox.darna', 'Chrome: inbox.darna · Majed'),
('support@darna-app.com', 'darna-app.com', 102400, '2028-07-11', 0, 'active',
 NULL, 'Hostinger Free Business Email'),
('ajmalnk@codoai.in', 'codoai.in', 1024, '2028-07-11', 0, 'active',
 'ai.codomail', 'Chrome: CODO AI / ai.codomail'),
('hr@codoai.in', 'codoai.in', 1024, '2028-07-11', 0, 'active',
 'hr.codomail', 'Chrome: hr.codomail'),
('info@codoai.in', 'codoai.in', 1024, '2028-07-11', 0, 'active',
 'codoai.com', 'Chrome: codoai.com profile'),
('next@codoai.in', 'codoai.in', 1024, '2028-07-11', 0, 'active',
 NULL, 'Hostinger Free Business Email'),
('hasanalnemer@prosystrd.com', 'prosystrd.com', 10240, '2027-07-26', 0, 'active',
 NULL, 'Starter Business Email'),
('hr@prosystrd.com', 'prosystrd.com', 10240, '2027-07-26', 0, 'active',
 NULL, 'Starter Business Email'),
('info@prosystrd.com', 'prosystrd.com', 10240, '2027-07-26', 0, 'active',
 NULL, 'Starter Business Email'),
('mustaq@prosystrd.com', 'prosystrd.com', 10240, '2027-07-26', 0, 'active',
 NULL, 'Starter Business Email'),
('bugs@moajmalnk.in', 'moajmalnk.in', 1024, '2028-07-11', 0, 'active',
 'bugricer', 'Chrome: bugricer / BugRicer Main'),
('hi@moajmalnk.in', 'moajmalnk.in', 1024, '2028-07-11', 0, 'active',
 'hi.ajmalnk', 'Chrome: hi.ajmalnk'),
('ai@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 'dev.codoacademy', 'Chrome: Developers CODO Academy'),
('ajmalnk@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 'creative.codoaca', 'Chrome: Creative CODO Academy'),
('bug@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 'codo.bugricer', 'Chrome: BugRicer CODO'),
('career@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 NULL, 'Hostinger Free Business Email'),
('hr@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 'hr.codomail', 'Chrome: hr.codomail'),
('info@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'suspended',
 'docs.codo', 'Suspended in Hostinger · Chrome: docs.codo'),
('mentor@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 NULL, 'Hostinger Free Business Email'),
('noreply@codoacademy.com', 'codoacademy.com', 1024, '2028-07-11', 0, 'active',
 NULL, 'Hostinger Free Business Email'),
('hr@phelectricals.co', 'phelectricals.co', 5120, '2027-09-23', 0, 'active',
 NULL, 'Starter Business Email'),
('info@phelectricals.co', 'phelectricals.co', 5120, '2027-09-23', 0, 'active',
 NULL, 'Starter Business Email'),
('procurement@phelectricals.co', 'phelectricals.co', 5120, '2027-09-23', 0, 'active',
 NULL, 'Starter Business Email'),
('sales1@phelectricals.co', 'phelectricals.co', 5120, '2027-09-23', 0, 'active',
 NULL, 'Starter Business Email'),
('zainab@phelectricals.co', 'phelectricals.co', 5120, '2027-09-23', 0, 'active',
 NULL, 'Starter Business Email'),
('zama@phelectricals.co', 'phelectricals.co', 5120, '2027-09-23', 0, 'active',
 NULL, 'Starter Business Email'),
('accounts@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email'),
('corporate@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email'),
('dammam@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email'),
('ismael@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email'),
('jubail@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email'),
('khobar@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email'),
('sales@multisafety.com.sa', 'multisafety.com.sa', 10240, '2027-09-24', 0, 'active',
 NULL, 'Starter Business Email — Catch-all'),
-- Albedo / Qmentr / ZeeQue from Chrome profile tiles (domain must exist)
('info@albedoedu.com', 'albedoedu.com', 1024, NULL, 0, 'active',
 'info@albedoedu', 'Chrome: info@albedoedu… · Albedo Educator'),
('operations@albedoedu.com', 'albedoedu.com', 1024, NULL, 0, 'active',
 'operations@albedo', 'Chrome: operations@albedo…'),
('marketing@albedoedu.com', 'albedoedu.com', 1024, NULL, 0, 'active',
 'marketing | Albedo', 'Chrome: marketing | Albedo'),
('info@qmentr.com', 'qmentr.com', 1024, NULL, 0, 'active',
 'info@qmentr.com', 'Chrome: info@qmentr.com'),
('info@zeequeplus.com', 'zeequeplus.com', 1024, NULL, 0, 'active',
 'info@zeequeplus', 'Chrome: info@zeequeplus… / inbox.zeeque'),
('finance@codoai.in', 'codoai.in', 1024, '2028-07-11', 0, 'active',
 'finance.codo', 'Chrome: finance.codo'),
('premium@codoai.in', 'codoai.in', 1024, NULL, 0, 'active',
 'premium.codomail', 'Chrome: premium.codomail · CODO PREMIUM');

-- Insert only when domain exists; never overwrite assignee
INSERT INTO assets_emails (
  id, domain_id, address, provider, storage_quota_mb, vendor,
  billing_cycle, currency, auto_renew, expires_at, status,
  signed_in_from, notes, created_at, updated_at
)
SELECT
  UUID(),
  d.id,
  m.address,
  'hostinger',
  m.quota_mb,
  'Hostinger',
  'yearly',
  'INR',
  m.auto_renew,
  m.expires_at,
  m.status,
  m.signed_in_from,
  m.notes,
  NOW(),
  NOW()
FROM tmp_mail_seed m
JOIN assets_domains d
  ON d.fqdn COLLATE utf8mb4_unicode_ci = m.apex COLLATE utf8mb4_unicode_ci
 AND d.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
  provider = 'hostinger',
  storage_quota_mb = COALESCE(VALUES(storage_quota_mb), assets_emails.storage_quota_mb),
  expires_at = COALESCE(VALUES(expires_at), assets_emails.expires_at),
  auto_renew = VALUES(auto_renew),
  status = VALUES(status),
  signed_in_from = COALESCE(VALUES(signed_in_from), assets_emails.signed_in_from),
  notes = COALESCE(VALUES(notes), assets_emails.notes),
  updated_at = NOW(),
  deleted_at = NULL;

-- Report which seed rows had no matching domain
SELECT m.address, m.apex, 'skipped — domain missing' AS reason
FROM tmp_mail_seed m
LEFT JOIN assets_domains d
  ON d.fqdn COLLATE utf8mb4_unicode_ci = m.apex COLLATE utf8mb4_unicode_ci
 AND d.deleted_at IS NULL
WHERE d.id IS NULL
ORDER BY m.apex, m.address;

SELECT 'emails' AS kind, COUNT(*) AS n
FROM assets_emails
WHERE deleted_at IS NULL
  AND signed_in_from IS NOT NULL
  AND signed_in_from <> '';

DROP TEMPORARY TABLE IF EXISTS tmp_mail_seed;
