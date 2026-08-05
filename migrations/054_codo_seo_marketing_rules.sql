-- Section 8: SEO, Tracking & Marketing mandates (selected Common CODO keys).
-- Keys: 33, 35, 36, 37, 38, 40, 43 (34 / 39 / 41 / 42 / 44 / 45 reserved until defined).
-- Safe to re-run (INSERT IGNORE). Backfills project_compliance_checks for existing projects.

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_33', 'Canonical Tag Injection', 'Rule 33',
 'Ensure all rendered pages include a self-referencing canonical tag.\n\nMalayalam: എല്ലാ റെൻഡർ ചെയ്യുന്ന പേജുകളിലും സെൽഫ്-റഫറൻസിംഗ് canonical ടാഗ് ഉണ്ടായിരിക്കണം.',
 33, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_35', 'Heading Hierarchy Enforcement', 'Rule 35',
 'Exactly one <h1> per page, followed sequentially by <h2>, <h3>.\n\nMalayalam: ഓരോ പേജിലും ഒരു <h1> മാത്രം; അതിന് ശേഷം <h2>, <h3> ക്രമത്തിൽ ഉപയോഗിക്കുക.',
 35, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_36', 'Image WebP & Alt Text Standard', 'Rule 36',
 'All image assets must use WebP extension and require non-empty alt text.\n\nMalayalam: എല്ലാ ഇമേജ് അസറ്റുകളും WebP ആയിരിക്കണം; ശൂന്യമല്ലാത്ത alt ടെക്സ്റ്റ് നിർബന്ധമാണ്.',
 36, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_37', 'Structured Data JSON-LD', 'Rule 37',
 'Inject valid JSON-LD schemas (Organization, LocalBusiness, FAQPage, etc.) into page metadata.\n\nMalayalam: Organization, LocalBusiness, FAQPage പോലുള്ള സാധുവായ JSON-LD സ്കീമകൾ പേജ് മെറ്റാഡാറ്റയിൽ ഇൻജക്റ്റ് ചെയ്യണം.',
 37, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_38', 'Conversion Telemetry & GA4 Event Tracking', 'Rule 38',
 'Attach gtag or analytics click event triggers to all WhatsApp, phone, and form submission buttons.\n\nMalayalam: WhatsApp, ഫോൺ, ഫോം സബ്മിറ്റ് ബട്ടണുകളിൽ gtag/അനലിറ്റിക്സ് ക്ലിക്ക് ഇവന്റുകൾ ബന്ധിപ്പിക്കണം.',
 38, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_40', 'Core Web Vitals Optimization', 'Rule 40',
 'Preload critical fonts, enforce image loading="lazy", and prevent layout shifts (CLS < 0.1).\n\nMalayalam: ക്രിട്ടിക്കൽ ഫോണ്ടുകൾ preload ചെയ്യുക, ഇമേജുകൾക്ക് loading="lazy" നൽകുക, ലേഔട്ട് ഷിഫ്റ്റ് തടയുക (CLS < 0.1).',
 40, 1);

INSERT IGNORE INTO `codo_common_rules` (`phase`, `rule_key`, `title`, `subtitle`, `description`, `sort_order`, `is_active`) VALUES
('developer', 'dev_rule_43', 'Custom 404 Routing', 'Rule 43',
 'Include a custom branded 404 page component for all unhandled routes.\n\nMalayalam: കൈകാര്യം ചെയ്യാത്ത എല്ലാ റൂട്ടുകൾക്കും ബ്രാൻഡഡ് കസ്റ്റം 404 പേജ് ഉണ്ടായിരിക്കണം.',
 43, 1);

-- ── Backfill compliance checks for existing projects ───────────────────────
INSERT IGNORE INTO `project_compliance_checks` (`project_id`, `phase`, `rule_key`, `verified`)
SELECT pc.`project_id`, 'developer', k.`rule_key`, 0
FROM `project_compliance` pc
CROSS JOIN (
  SELECT 'dev_rule_33' AS `rule_key` UNION ALL
  SELECT 'dev_rule_35' UNION ALL
  SELECT 'dev_rule_36' UNION ALL
  SELECT 'dev_rule_37' UNION ALL
  SELECT 'dev_rule_38' UNION ALL
  SELECT 'dev_rule_40' UNION ALL
  SELECT 'dev_rule_43'
) k;
