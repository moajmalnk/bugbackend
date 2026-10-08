-- CODO DEV-69..72 + QA Googlebot HTML drill: SPA / blog SEO crawlability
-- Real-world source: Google "Duplicate, Google chose different canonical" on SPA blogs
-- where bots received unique meta but empty #root (no article body).
-- Idempotent: INSERT IGNORE + open-project compliance backfill

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_69', 'Bot-Visible Unique Content (SPA Crawlability)', 'Rule 69',
 'For every public blog/article (and similar content pages) in a client-rendered SPA, the first HTML response to Googlebot must contain that page''s unique H1, article body (or equivalent main content), self-referencing canonical, and Article/Breadcrumb JSON-LD. Meta tags alone with an empty #root are a defect: Google may merge unrelated URLs into one canonical. Prefer SSR, SSG, prerender, or bot middleware that injects content. Verify with curl -A Googlebot (View Source), not DevTools DOM.\n\nMalayalam: SPA public blog/article first HTML must include unique H1, body, self-canonical, Article/Breadcrumb JSON-LD for Googlebot. Empty #root + meta only is a defect. Verify with curl -A Googlebot.',
 69, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_70', 'Trailing Slash URL Standardization', 'Rule 70',
 'Pick one URL style sitewide (prefer no trailing slash except root /). Slash variants must 301 once to the preferred URL with no redirect chain. Canonicals, sitemap locs, footer/menu/in-content links, and router paths must all match. Never leave /contact/ and /contact both returning 200.\n\nMalayalam: One URL style sitewide (prefer no trailing slash). Slash URLs must 301 once. Canonical, sitemap and links must match.',
 70, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_71', 'Unique Per-Page Metadata & Schema Isolation', 'Rule 71',
 'Each indexable page must emit its own unique <title>, H1, meta description, and self-canonical that match the page. Do not share default titles/descriptions across posts. Do not inject sitewide Organization/WebSite JSON-LD on every article while rewriting WebSite.description to each post excerpt. Related-post modules must be title+link (short excerpt at most) with data-nosnippet when needed — never embed other posts'' full body text.\n\nMalayalam: Each page needs unique title/H1/description/canonical. Do not reuse sitewide WebSite schema on every article. Related posts = titles+links only.',
 71, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_72', 'Sitemap Canonical Hygiene', 'Rule 72',
 'sitemap.xml must return 200 with valid XML under a reliable timeout, list only final canonical URLs (no slash duplicates, no redirected URLs, no noindex pages), and use accurate lastmod only when content actually changes. Prefer an owned endpoint with caching over a flaky upstream proxy that causes Search Console temporary processing errors.\n\nMalayalam: sitemap.xml must be 200 + valid XML; only final canonical URLs; avoid flaky proxy timeouts.',
 72, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('tester', 'qa_googlebot_html', 'Googlebot HTML Uniqueness Drill', 'QA Stress 36',
 'For public content URLs (especially blogs), fetch with curl -A Googlebot or Search Console URL Inspection → Test Live URL → View tested page HTML. Reject if #root is empty, H1/body text is missing, canonical is wrong/shared, slash URLs do not 301 once, or sitemap fails. Browser DevTools Elements panel after JS runs is not acceptance.\n\nMalayalam: Check public URLs with curl -A Googlebot or GSC tested HTML. Reject empty #root / missing body / bad canonical / missing slash 301 / sitemap fail. DevTools-after-JS is not acceptance.',
 36, 1);

UPDATE `codo_common_rules`
SET `description` = 'Ensure every indexable page outputs a self-referencing canonical to its final preferred URL (usually no trailing slash). Never point Page A''s canonical at Page B to clear a Search Console warning — that removes A from search. Canonical alone is not enough if the article body is missing from the first HTML response (see Rule 69).\n\nMalayalam: Every indexable page needs a self-referencing canonical to its final URL. Never retarget canonical to another page to clear GSC. Meta-only without body is not enough (Rule 69).',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `rule_key` = 'dev_rule_33';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'dev_rule_69' AS `rule_key` UNION ALL
  SELECT 'dev_rule_70' UNION ALL
  SELECT 'dev_rule_71' UNION ALL
  SELECT 'dev_rule_72'
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';

INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'tester', k.`rule_key`, 0
FROM `project_compliance` pc
JOIN `projects` p ON p.`id` = pc.`project_id`
CROSS JOIN (
  SELECT 'qa_googlebot_html' AS `rule_key`
) k
WHERE p.`status` NOT IN ('completed', 'release_ready', 'archived')
  AND pc.`pipeline_stage` <> 'admin_ready';
