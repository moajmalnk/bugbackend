-- =============================================================================
-- BugRicer Migration 106 — Seed Hostinger inventory from panel screenshots
-- Safe to re-run (upserts by unique fqdn / address / hostname / label).
-- Generated 2026-09-26 from Hostinger Domains / Websites / Emails panels.
-- =============================================================================
-- BEFORE RUN — edit SECTION A client_code values to match your Clients page.
-- Domains need a client_id. Unknowns default to 'Codo Internal (Hostinger)'.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = 'utf8mb4_unicode_ci';
SET @db := DATABASE();

-- Why: phpMyAdmin/Hostinger often mix utf8mb4_general_ci (session) with
-- utf8mb4_unicode_ci (table columns). Force one collation on temp tables + joins.
-- ----------------------------------------------------------------
-- SECTION A — Client mapping (edit client_code to match production)
-- ----------------------------------------------------------------
-- Resolve helper: prefer existing client_code; else create by corporate_name.
DROP TEMPORARY TABLE IF EXISTS tmp_asset_client_map;
CREATE TEMPORARY TABLE tmp_asset_client_map (
  map_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  client_code VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  corporate_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_asset_client_map (map_key, client_code, corporate_name) VALUES
  ('internal',     NULL,       'Codo Internal (Hostinger)'),
  ('codoai',       'CLT-0001', 'Codo AI'),                 -- confirmed on BugAssets
  ('codoacademy',  NULL,       'Codo Academy'),
  ('finbro',       NULL,       'FinBro'),
  ('pvk',          NULL,       'PVK Enterprises'),
  ('darna',        NULL,       'Darna App'),
  ('prosystrd',    NULL,       'Prosys Trading'),
  ('multisafety',  NULL,       'Multi Safety'),
  ('phelectricals',NULL,       'PH Electricals'),
  ('albedoedu',    NULL,       'Albedo Education'),
  ('kugoriental',  NULL,       'Kug Oriental'),
  ('thazquedu',    NULL,       'Thazqu Education'),
  ('hasee',        NULL,       'Hasee'),
  ('foreignedu',   NULL,       'Foreign Edu');

-- Next free CLT-#### (client_code is NOT NULL + UNIQUE — never insert NULL/'')
SET @n := (
  SELECT COALESCE(MAX(CAST(SUBSTRING(client_code, 5) AS UNSIGNED)), 0)
  FROM clients
  WHERE client_code REGEXP '^CLT-[0-9]+$'
);

-- Create missing clients with unique codes assigned upfront
INSERT INTO clients (id, client_code, corporate_name, commercial_status, created_at, updated_at)
SELECT
  UUID(),
  COALESCE(
    NULLIF(TRIM(m.client_code), ''),
    CONCAT('CLT-', LPAD((@n := @n + 1), 4, '0'))
  ),
  m.corporate_name,
  'active',
  NOW(),
  NOW()
FROM tmp_asset_client_map m
LEFT JOIN clients c
  ON c.corporate_name COLLATE utf8mb4_unicode_ci = m.corporate_name COLLATE utf8mb4_unicode_ci
  OR (
    m.client_code IS NOT NULL
    AND TRIM(m.client_code) <> ''
    AND c.client_code COLLATE utf8mb4_unicode_ci = m.client_code COLLATE utf8mb4_unicode_ci
  )
WHERE c.id IS NULL
GROUP BY m.map_key, m.client_code, m.corporate_name;

DROP TEMPORARY TABLE IF EXISTS tmp_cid;
CREATE TEMPORARY TABLE tmp_cid (
  map_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  client_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_cid (map_key, client_id)
SELECT m.map_key, MIN(c.id)
FROM tmp_asset_client_map m
JOIN clients c
  ON (
    m.client_code IS NOT NULL
    AND TRIM(m.client_code) <> ''
    AND c.client_code COLLATE utf8mb4_unicode_ci = m.client_code COLLATE utf8mb4_unicode_ci
  )
  OR c.corporate_name COLLATE utf8mb4_unicode_ci = m.corporate_name COLLATE utf8mb4_unicode_ci
GROUP BY m.map_key;

-- ----------------------------------------------------------------
-- SECTION B — Infrastructure nodes
-- ----------------------------------------------------------------
-- NK Server (VPS) — websites panel: expires 2027-08-21
SET @nk_id := (SELECT id FROM assets_servers WHERE hostname = 'NK Server' AND deleted_at IS NULL LIMIT 1);
SET @nk_id := IFNULL(@nk_id, UUID());

INSERT INTO assets_servers (
  id, hostname, vendor, billing_cycle, currency, auto_renew,
  expires_at, status, ownership, notes, created_at, updated_at
) VALUES (
  @nk_id, 'NK Server', 'Hostinger', 'yearly', 'INR', 1,
  '2027-08-21', 'active', 'shared',
  'Hostinger VPS — websites panel "NK Server"',
  NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
  vendor = VALUES(vendor),
  expires_at = VALUES(expires_at),
  status = VALUES(status),
  notes = VALUES(notes),
  updated_at = NOW(),
  deleted_at = NULL;

SET @nk_id := (SELECT id FROM assets_servers WHERE hostname = 'NK Server' AND deleted_at IS NULL LIMIT 1);

-- Business Web Hosting plan — expires 2028-07-10 (62 websites)
SET @biz_id := (
  SELECT id FROM assets_hosting
  WHERE label = 'Hostinger Business Web Hosting' AND deleted_at IS NULL
  ORDER BY created_at ASC LIMIT 1
);

INSERT INTO assets_hosting (
  id, label, package_name, vendor, billing_cycle, currency, auto_renew,
  expires_at, status, notes, created_at, updated_at
)
SELECT
  UUID(),
  'Hostinger Business Web Hosting',
  'Business',
  'Hostinger',
  'yearly',
  'INR',
  1,
  '2028-07-10',
  'active',
  'Hostinger Websites plan — 62 owned websites (partial seed from screenshots)',
  NOW(),
  NOW()
FROM DUAL
WHERE @biz_id IS NULL;

UPDATE assets_hosting
SET
  package_name = 'Business',
  vendor = 'Hostinger',
  expires_at = '2028-07-10',
  status = 'active',
  notes = 'Hostinger Websites plan — 62 owned websites (partial seed from screenshots)',
  updated_at = NOW(),
  deleted_at = NULL
WHERE label = 'Hostinger Business Web Hosting' AND deleted_at IS NULL;

SET @biz_id := (
  SELECT id FROM assets_hosting
  WHERE label = 'Hostinger Business Web Hosting' AND deleted_at IS NULL
  ORDER BY created_at ASC LIMIT 1
);

-- ----------------------------------------------------------------
-- SECTION C — Root domains (upsert by fqdn)
-- expires_at = domain registration when known; else email-plan / hosting hint in notes
-- ----------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_domains;
CREATE TEMPORARY TABLE tmp_domains (
  fqdn VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  map_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  registrar VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  dns_provider VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  nameservers JSON NULL,
  expires_at DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_domains (fqdn, map_key, registrar, dns_provider, nameservers, expires_at, auto_renew, status, notes) VALUES
-- Internal / Codo
('codoai.in',          'codoai',      'Hostinger', 'Hostinger', JSON_ARRAY('ns1.dns-parking.com','ns2.dns-parking.com'), '2029-01-30', 1, 'active', 'Domain + Hostinger Free Business Email (plan exp 2028-07-11)'),
('codoai.cloud',       'codoai',      'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On Business web hosting'),
('codoacademy.com',    'codoacademy', 'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'Free Business Email plan exp 2028-07-11; auto-renew Off on mail'),
('bugricer.com',       'internal',    'Hostinger', 'Hostinger', NULL, '2026-09-15', 0, 'expired', 'Starter Business Email Free Trial expired 2026-09-15'),
('moajmalnk.in',       'internal',    'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'Free Business Email plan exp 2028-07-11'),
('moajmalnk.com',      'internal',    'Hostinger', 'Hostinger', NULL, NULL, 0, 'expired', 'Domain expired — renew; Starter Email free trial exp 2027-04-17'),
('moajmalnk.cloud',    'internal',    'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On NK Server'),
-- Clients
('finbro.cloud',       'finbro',      'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On NK Server'),
('pvkenterprises.com', 'pvk',         'Hostinger', 'Hostinger', NULL, NULL, 0, 'active', 'Free Business Email plan exp 2028-07-11; auto-renew Off'),
('darna-app.com',      'darna',       'Hostinger', 'Hostinger', NULL, NULL, 0, 'active', 'Panel also shows dama-app.com in email list — confirm spelling'),
('prosystrd.com',      'prosystrd',   'Hostinger', 'Hostinger', NULL, '2027-07-26', 0, 'active', 'Starter Business Email; confirm vs pressystrd.com spelling'),
('multisafety.com.sa', 'multisafety', 'Hostinger', 'Hostinger', NULL, '2027-09-24', 0, 'active', 'Starter Business Email; also on NK Server'),
('phelectricals.co',   'phelectricals','Hostinger','Hostinger', NULL, '2027-09-23', 0, 'active', 'Starter Business Email 6/6 mailboxes'),
('albedoedu.com',      'albedoedu',   'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On Business web hosting'),
('kugoriental.com',    'kugoriental', 'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On Business web hosting'),
('thazquedu.com',      'thazquedu',   'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On Business web hosting'),
('hasee.in',           'hasee',       'Hostinger', 'Hostinger', NULL, NULL, 1, 'active', 'On NK Server'),
('foreignedu.co',      'foreignedu',  'Hostinger', 'Hostinger', NULL, NULL, 0, 'pending', 'Starter Email — "Email is not working / Connect domain"');

INSERT INTO assets_domains (
  id, client_id, fqdn, registrar, dns_provider, nameservers, vendor,
  billing_cycle, currency, auto_renew, expires_at, status, notes, created_at, updated_at
)
SELECT
  UUID(),
  cid.client_id,
  t.fqdn,
  t.registrar,
  t.dns_provider,
  t.nameservers,
  'Hostinger',
  'yearly',
  'INR',
  t.auto_renew,
  t.expires_at,
  t.status,
  t.notes,
  NOW(),
  NOW()
FROM tmp_domains t
JOIN tmp_cid cid ON cid.map_key COLLATE utf8mb4_unicode_ci = t.map_key COLLATE utf8mb4_unicode_ci
ON DUPLICATE KEY UPDATE
  registrar = COALESCE(VALUES(registrar), assets_domains.registrar),
  dns_provider = COALESCE(VALUES(dns_provider), assets_domains.dns_provider),
  nameservers = COALESCE(VALUES(nameservers), assets_domains.nameservers),
  vendor = 'Hostinger',
  auto_renew = VALUES(auto_renew),
  expires_at = COALESCE(VALUES(expires_at), assets_domains.expires_at),
  status = VALUES(status),
  notes = VALUES(notes),
  updated_at = NOW(),
  deleted_at = NULL;

-- ----------------------------------------------------------------
-- SECTION D — Subdomains / websites
-- target_kind: server = NK Server, hosting = Business plan
-- host = leftmost label; apex websites use host = '@'
-- ----------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_subs;
CREATE TEMPORARY TABLE tmp_subs (
  fqdn VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  apex VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  host VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  purpose VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  target_kind ENUM('server','hosting','vercel','raw') NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_subs (fqdn, apex, host, purpose, target_kind, status) VALUES
-- NK Server
('tradeapi.finbro.cloud',     'finbro.cloud',       'tradeapi',  'Trade API',           'server', 'active'),
('trade.finbro.cloud',        'finbro.cloud',       'trade',     'Trade app',           'server', 'active'),
('api.finbro.cloud',          'finbro.cloud',       'api',       'API',                 'server', 'active'),
('finbro.cloud',              'finbro.cloud',       '@',         'Apex site',           'server', 'active'),
('flowapi.bugricer.com',      'bugricer.com',       'flowapi',   'Flow API',            'server', 'active'),
('flow.bugricer.com',         'bugricer.com',       'flow',      'Flow app',            'server', 'active'),
('notifyapi.bugricer.com',    'bugricer.com',       'notifyapi', 'Notify API',          'server', 'active'),
('notify.bugricer.com',       'bugricer.com',       'notify',    'Notify app',          'server', 'active'),
('papi.moajmalnk.com',        'moajmalnk.com',      'papi',      'P API (parent expired)','server', 'active'),
('pvk.moajmalnk.com',         'moajmalnk.com',      'pvk',       'PVK (parent expired)','server', 'active'),
('sumapi.moajmalnk.cloud',    'moajmalnk.cloud',    'sumapi',    'Sum API',             'server', 'active'),
('sum.moajmalnk.cloud',       'moajmalnk.cloud',    'sum',       'Sum app',             'server', 'active'),
('moajmalnk.cloud',           'moajmalnk.cloud',    '@',         'Apex site',           'server', 'active'),
('hasee.in',                  'hasee.in',           '@',         'Apex site',           'server', 'active'),
('multisafety.com.sa',        'multisafety.com.sa', '@',         'Apex site',           'server', 'active'),
-- Business web hosting (from screenshots — not full 62)
('npi.codoai.in',             'codoai.in',          'npi',       NULL,                  'hosting', 'active'),
('next.codoai.in',            'codoai.in',          'next',      NULL,                  'hosting', 'active'),
('api.codoai.in',             'codoai.in',          'api',       NULL,                  'hosting', 'active'),
('itinerary.codoai.in',       'codoai.in',          'itinerary', NULL,                  'hosting', 'active'),
('travel.codoai.in',          'codoai.in',          'travel',    NULL,                  'hosting', 'active'),
('hrmapi.codoai.in',          'codoai.in',          'hrmapi',    'HRM API',             'hosting', 'active'),
('hrm.codoai.in',             'codoai.in',          'hrm',       'HRM',                 'hosting', 'active'),
('codoai.in',                 'codoai.in',          '@',         'Apex site',           'hosting', 'active'),
('operationsapi.codoacademy.com','codoacademy.com', 'operationsapi', NULL,             'hosting', 'active'),
('operations.codoacademy.com','codoacademy.com',    'operations',NULL,                  'hosting', 'active'),
('api.codoacademy.com',       'codoacademy.com',    'api',       NULL,                  'hosting', 'active'),
('cbmsapi.codoacademy.com',   'codoacademy.com',    'cbmsapi',   'CBMS API',            'hosting', 'active'),
('cbms.codoacademy.com',      'codoacademy.com',    'cbms',      'CBMS',                'hosting', 'active'),
('bot.codoacademy.com',       'codoacademy.com',    'bot',       NULL,                  'hosting', 'active'),
('converter.codoacademy.com', 'codoacademy.com',    'converter', NULL,                  'hosting', 'active'),
('solutions.codoacademy.com', 'codoacademy.com',    'solutions', NULL,                  'hosting', 'active'),
('insights.codoacademy.com',  'codoacademy.com',    'insights',  NULL,                  'hosting', 'active'),
('service.codoacademy.com',   'codoacademy.com',    'service',   NULL,                  'hosting', 'active'),
('docs.codoacademy.com',      'codoacademy.com',    'docs',      NULL,                  'hosting', 'active'),
('support.codoacademy.com',   'codoacademy.com',    'support',   NULL,                  'hosting', 'active'),
('marketingapi.albedoedu.com','albedoedu.com',      'marketingapi', NULL,              'hosting', 'active'),
('hr.albedoedu.com',          'albedoedu.com',      'hr',        NULL,                  'hosting', 'active'),
('marketing.albedoedu.com',   'albedoedu.com',      'marketing', NULL,                  'hosting', 'active'),
('webapi.albedoedu.com',      'albedoedu.com',      'webapi',    NULL,                  'hosting', 'active'),
('spi.albedoedu.com',         'albedoedu.com',      'spi',       NULL,                  'hosting', 'active'),
('backup.albedoedu.com',      'albedoedu.com',      'backup',    NULL,                  'hosting', 'active'),
('web.albedoedu.com',         'albedoedu.com',      'web',       NULL,                  'hosting', 'active'),
('new.albedoedu.com',         'albedoedu.com',      'new',       NULL,                  'hosting', 'active'),
('pay.albedoedu.com',         'albedoedu.com',      'pay',       NULL,                  'hosting', 'active'),
('api.albedoedu.com',         'albedoedu.com',      'api',       NULL,                  'hosting', 'active'),
('calcbackend.albedoedu.com', 'albedoedu.com',      'calcbackend', NULL,               'hosting', 'active'),
('calc.albedoedu.com',        'albedoedu.com',      'calc',      NULL,                  'hosting', 'active'),
('support.albedoedu.com',     'albedoedu.com',      'support',   NULL,                  'hosting', 'active'),
('faisaltk.albedoedu.com',    'albedoedu.com',      'faisaltk',  NULL,                  'hosting', 'active'),
('operations.albedoedu.com',  'albedoedu.com',      'operations',NULL,                  'hosting', 'active'),
('albedoedu.com',             'albedoedu.com',      '@',         'Apex site',           'hosting', 'active'),
('webapi.kugoriental.com',    'kugoriental.com',    'webapi',    NULL,                  'hosting', 'active'),
('result.kugoriental.com',    'kugoriental.com',    'result',    NULL,                  'hosting', 'active'),
('api.kugoriental.com',       'kugoriental.com',    'api',       NULL,                  'hosting', 'active'),
('kugoriental.com',           'kugoriental.com',    '@',         'Apex site',           'hosting', 'active'),
('api.darna-app.com',         'darna-app.com',      'api',       NULL,                  'hosting', 'active'),
('darna-app.com',             'darna-app.com',      '@',         'Apex site',           'hosting', 'active'),
('codoai.cloud',              'codoai.cloud',       '@',         'Apex site',           'hosting', 'active'),
('api.pvkenterprises.com',    'pvkenterprises.com', 'api',       NULL,                  'hosting', 'active'),
('crm.pvkenterprises.com',    'pvkenterprises.com', 'crm',       NULL,                  'hosting', 'active'),
('pvkenterprises.com',        'pvkenterprises.com', '@',         'Apex site',           'hosting', 'active'),
('skillapi.moajmalnk.in',     'moajmalnk.in',       'skillapi',  NULL,                  'hosting', 'active'),
('skillmount.moajmalnk.in',   'moajmalnk.in',       'skillmount',NULL,                  'hosting', 'active'),
('api.moajmalnk.in',          'moajmalnk.in',       'api',       NULL,                  'hosting', 'active'),
('n8n.moajmalnk.in',          'moajmalnk.in',       'n8n',       'n8n',                 'hosting', 'active'),
('evoka.moajmalnk.in',        'moajmalnk.in',       'evoka',     NULL,                  'hosting', 'active'),
('course.moajmalnk.in',       'moajmalnk.in',       'course',    'Domain is connecting','hosting', 'active'),
('admission.moajmalnk.in',    'moajmalnk.in',       'admission', NULL,                  'hosting', 'active'),
('admissionbackend.moajmalnk.in','moajmalnk.in',    'admissionbackend', NULL,           'hosting', 'active'),
('todobackend.moajmalnk.in',  'moajmalnk.in',       'todobackend', NULL,               'hosting', 'active'),
('todo.moajmalnk.in',         'moajmalnk.in',       'todo',      'Domain is connecting','hosting', 'active'),
('qr.moajmalnk.in',           'moajmalnk.in',       'qr',        NULL,                  'hosting', 'active'),
('moajmalnk.in',              'moajmalnk.in',       '@',         'Apex site',           'hosting', 'active'),
('api.thazquedu.com',         'thazquedu.com',      'api',       NULL,                  'hosting', 'active'),
('thazquedu.com',             'thazquedu.com',      '@',         'Apex site',           'hosting', 'active'),
('prosystrd.com',             'prosystrd.com',      '@',         'Apex site',           'hosting', 'active');

INSERT INTO assets_subdomains (
  id, domain_id, host, fqdn, purpose, record_type, target_kind,
  target_server_id, target_hosting_id, status, created_at, updated_at
)
SELECT
  UUID(),
  d.id,
  s.host,
  s.fqdn,
  s.purpose,
  'A',
  s.target_kind,
  IF(s.target_kind = 'server', @nk_id, NULL),
  IF(s.target_kind = 'hosting', @biz_id, NULL),
  s.status,
  NOW(),
  NOW()
FROM tmp_subs s
JOIN assets_domains d
  ON d.fqdn COLLATE utf8mb4_unicode_ci = s.apex COLLATE utf8mb4_unicode_ci
 AND d.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
  purpose = COALESCE(VALUES(purpose), assets_subdomains.purpose),
  target_kind = VALUES(target_kind),
  target_server_id = VALUES(target_server_id),
  target_hosting_id = VALUES(target_hosting_id),
  status = VALUES(status),
  updated_at = NOW(),
  deleted_at = NULL;

-- ----------------------------------------------------------------
-- SECTION E — Mailboxes
-- Email plan expiry stored on each mailbox; quotas from panel
-- ----------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_mails;
CREATE TEMPORARY TABLE tmp_mails (
  address VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  apex VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  quota_mb INT UNSIGNED NULL,
  expires_at DATE NULL,
  auto_renew TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_mails (address, apex, quota_mb, expires_at, auto_renew, status, notes) VALUES
-- pvkenterprises.com — Free, 100 GB
('info@pvkenterprises.com',        'pvkenterprises.com', 102400, '2028-07-11', 0, 'active', 'Free Business Email'),
-- darna-app.com — Free, 100 GB (confirm domain spelling vs dama-app.com)
('malmajed@darna-app.com',         'darna-app.com',      102400, '2028-07-11', 0, 'active', 'Free Business Email'),
('support@darna-app.com',          'darna-app.com',      102400, '2028-07-11', 0, 'active', 'Free Business Email'),
-- codoai.in — Free, 1 GB
('ajmalnk@codoai.in',              'codoai.in',           1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('hr@codoai.in',                   'codoai.in',           1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('info@codoai.in',                 'codoai.in',           1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('next@codoai.in',                 'codoai.in',           1024, '2028-07-11', 0, 'active', 'Free Business Email'),
-- prosystrd.com — Starter, 10 GB
('hasanalnemer@prosystrd.com',     'prosystrd.com',      10240, '2027-07-26', 0, 'active', 'Starter Business Email'),
('hr@prosystrd.com',               'prosystrd.com',      10240, '2027-07-26', 0, 'active', 'Starter Business Email'),
('info@prosystrd.com',             'prosystrd.com',      10240, '2027-07-26', 0, 'active', 'Starter Business Email'),
('mustaq@prosystrd.com',           'prosystrd.com',      10240, '2027-07-26', 0, 'active', 'Starter Business Email'),
-- moajmalnk.in — Free, 1 GB
('bugs@moajmalnk.in',              'moajmalnk.in',        1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('hi@moajmalnk.in',                'moajmalnk.in',        1024, '2028-07-11', 0, 'active', 'Free Business Email'),
-- codoacademy.com — Free, 1 GB
('ai@codoacademy.com',             'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('ajmalnk@codoacademy.com',        'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('bug@codoacademy.com',            'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('career@codoacademy.com',         'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('hr@codoacademy.com',             'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('info@codoacademy.com',           'codoacademy.com',     1024, '2028-07-11', 0, 'suspended', 'Free Business Email — Suspended in panel'),
('mentor@codoacademy.com',         'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
('noreply@codoacademy.com',        'codoacademy.com',     1024, '2028-07-11', 0, 'active', 'Free Business Email'),
-- phelectricals.co — Starter, 5 GB
('hr@phelectricals.co',            'phelectricals.co',    5120, '2027-09-23', 0, 'active', 'Starter Business Email'),
('info@phelectricals.co',          'phelectricals.co',    5120, '2027-09-23', 0, 'active', 'Starter Business Email'),
('procurement@phelectricals.co',   'phelectricals.co',    5120, '2027-09-23', 0, 'active', 'Starter Business Email'),
('sales1@phelectricals.co',        'phelectricals.co',    5120, '2027-09-23', 0, 'active', 'Starter Business Email'),
('zainab@phelectricals.co',        'phelectricals.co',    5120, '2027-09-23', 0, 'active', 'Starter Business Email'),
('zama@phelectricals.co',          'phelectricals.co',    5120, '2027-09-23', 0, 'active', 'Starter Business Email'),
-- multisafety.com.sa — Starter, 10 GB
('accounts@multisafety.com.sa',    'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email'),
('corporate@multisafety.com.sa',   'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email'),
('dammam@multisafety.com.sa',      'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email'),
('ismael@multisafety.com.sa',      'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email'),
('jubail@multisafety.com.sa',      'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email'),
('khobar@multisafety.com.sa',      'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email'),
('sales@multisafety.com.sa',       'multisafety.com.sa', 10240, '2027-09-24', 0, 'active', 'Starter Business Email — Catch-all');

INSERT INTO assets_emails (
  id, domain_id, address, provider, storage_quota_mb, vendor,
  billing_cycle, currency, auto_renew, expires_at, status, notes, created_at, updated_at
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
  m.notes,
  NOW(),
  NOW()
FROM tmp_mails m
JOIN assets_domains d
  ON d.fqdn COLLATE utf8mb4_unicode_ci = m.apex COLLATE utf8mb4_unicode_ci
 AND d.deleted_at IS NULL
ON DUPLICATE KEY UPDATE
  provider = 'hostinger',
  storage_quota_mb = VALUES(storage_quota_mb),
  vendor = 'Hostinger',
  auto_renew = VALUES(auto_renew),
  expires_at = VALUES(expires_at),
  status = VALUES(status),
  notes = VALUES(notes),
  updated_at = NOW(),
  deleted_at = NULL;

-- ----------------------------------------------------------------
-- SECTION F — Sanity counts
-- ----------------------------------------------------------------
SELECT 'domains' AS kind, COUNT(*) AS n FROM assets_domains WHERE deleted_at IS NULL
UNION ALL
SELECT 'subdomains', COUNT(*) FROM assets_subdomains WHERE deleted_at IS NULL
UNION ALL
SELECT 'emails', COUNT(*) FROM assets_emails WHERE deleted_at IS NULL
UNION ALL
SELECT 'servers', COUNT(*) FROM assets_servers WHERE deleted_at IS NULL
UNION ALL
SELECT 'hosting', COUNT(*) FROM assets_hosting WHERE deleted_at IS NULL;

-- Cleanup temps
DROP TEMPORARY TABLE IF EXISTS tmp_mails;
DROP TEMPORARY TABLE IF EXISTS tmp_subs;
DROP TEMPORARY TABLE IF EXISTS tmp_domains;
DROP TEMPORARY TABLE IF EXISTS tmp_cid;
DROP TEMPORARY TABLE IF EXISTS tmp_asset_client_map;
