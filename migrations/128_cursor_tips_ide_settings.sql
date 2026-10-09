-- =============================================================================
-- BugRicer Migration 128 — Cursor Tips: IDE settings.json for Cursor / Antigravity
-- Safe to re-run. Upserts by (phase, tip_key); requires 103_cursor_tips.sql.
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('workflow', 'cursor_wf_ide_settings', 'Install CODO IDE settings.json', 'Workflow 13',
 'Cursor and Antigravity both need the BugRicer CODO `settings.json` pack (format-on-save, ESLint fix, Problems decorations, git badges). Download it from Common CODO → Export on the Cursor or Antigravity card. Prefer workspace `.vscode/settings.json` so the whole team shares the same editor defaults; or merge into your User settings.json without wiping your theme.\n\nMalayalam: Cursor-ഉം Antigravity-യും BugRicer CODO `settings.json` പായ്ക്ക് വേണം (format-on-save, ESLint, Problems, git badges). Common CODO → Export-ൽ നിന്ന് ഡൗൺലോഡ് ചെയ്യുക. ടീമിന് `.vscode/settings.json` നല്ലത്; അല്ലെങ്കിൽ User settings-ൽ merge ചെയ്യുക — തീം മായ്ക്കരുത്.',
 'Giving every carpenter the same tape measure and square before they start building.',
 'എല്ലാ മരപ്പണിക്കാർക്കും ഒരേ അളവുപാട്ടിയും സ്ക്വയറും നൽകുന്നത് പോലെ.',
 'First day on BugRicer in Cursor or Antigravity, and after CODO updates the recommended settings pack.',
 'When you already have an identical `.vscode/settings.json` committed and verified.',
 'Skipping settings.json and relying on a personal theme-only User config with no format-on-save',
 '1) Common CODO → Export → Cursor (or Antigravity)\n2) Download settings.json\n3) Save as .vscode/settings.json (commit) OR merge into User settings\n4) Reload window — confirm Problems + format-on-save work',
 'Text', 13, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `subtitle` = VALUES(`subtitle`),
  `description` = VALUES(`description`),
  `analogy_en` = VALUES(`analogy_en`),
  `analogy_ml` = VALUES(`analogy_ml`),
  `when_to_use` = VALUES(`when_to_use`),
  `when_not_to_use` = VALUES(`when_not_to_use`),
  `example_bad` = VALUES(`example_bad`),
  `example_good` = VALUES(`example_good`),
  `example_language` = VALUES(`example_language`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = VALUES(`is_active`),
  `deleted_at` = NULL,
  `deleted_by` = NULL;
