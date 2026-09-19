-- Cursor Tips catalog (sibling to Common CODO — operating craft for Cursor)
-- utf8mb4 · INSERT IGNORE seeds · VIEW/MANAGE permissions

CREATE TABLE IF NOT EXISTS `cursor_tips` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phase` ENUM('modes','commands','skills','workflow','review') NOT NULL,
  `tip_key` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `subtitle` VARCHAR(255) NULL DEFAULT NULL,
  `description` TEXT NOT NULL,
  `analogy_en` TEXT NULL DEFAULT NULL,
  `analogy_ml` TEXT NULL DEFAULT NULL,
  `when_to_use` TEXT NULL DEFAULT NULL,
  `when_not_to_use` TEXT NULL DEFAULT NULL,
  `example_bad` TEXT NULL DEFAULT NULL,
  `example_good` TEXT NULL DEFAULT NULL,
  `example_language` VARCHAR(40) NULL DEFAULT 'Prompt',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(36) NULL DEFAULT NULL,
  `updated_by` VARCHAR(36) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `deleted_by` VARCHAR(36) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cursor_tips_phase_key` (`phase`, `tip_key`),
  KEY `idx_cursor_tips_phase_active` (`phase`, `is_active`),
  KEY `idx_cursor_tips_sort` (`phase`, `sort_order`),
  KEY `idx_cursor_tips_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Modes ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('modes', 'cursor_mode_ask', 'Ask', 'Mode 1',
 'Use Ask to inspect the codebase without mutating files. Prefer it for tracing flows, confirming intent, and reviewing unfamiliar modules.\n\nMalayalam: കോഡ് മാറ്റാതെ മനസ്സിലാക്കാൻ Ask ഉപയോഗിക്കുക. ഫ്ലോ ട്രേസ് ചെയ്യാനും ഉദ്ദേശം ഉറപ്പാക്കാനും അപരിചിത മൊഡ്യൂളുകൾ വായിക്കാനും ഇത് യോജിക്കും.',
 'A site survey before construction.',
 'പണി തുടങ്ങും മുമ്പുള്ള സൈറ്റ് സർവേ.',
 'Tracing flows, confirming intent, reading unfamiliar modules.',
 'When you already know the edit surface and need files changed.',
 '“Fix the salary crash.” in Ask',
 '“Trace how teacher salary totals are computed in teacher_salary_report_model.dart. Do not edit.”',
 'Prompt', 1, 1),

('modes', 'cursor_mode_plan', 'Plan', 'Mode 2',
 'Use Plan for multi-file or ambiguous work. Produce a reviewable blueprint, then build only after approval.\n\nMalayalam: ഒന്നിലധികം ഫയലുകളോ അവ്യക്തമായ സ്കോപ്പോ ഉള്ള ജോലിക്ക് ആദ്യം Plan ഉപയോഗിച്ച് ബ്ലൂപ്രിന്റ് തയ്യാറാക്കുക. അംഗീകാരത്തിന് ശേഷം മാത്രം ബിൽഡ് ചെയ്യുക.',
 'Architectural drawings before the first brick.',
 'ആദ്യ ഇഷ്ടികയ്ക്ക് മുമ്പുള്ള ആർക്കിടെക്ചറൽ ഡ്രോയിംഗുകൾ.',
 'Multi-file features, ambiguous scope, production-ready blueprints.',
 'Tiny one-file fixes with a clear acceptance path.',
 'Jumping into Agent on a vague “finish salary feature”',
 '“Plan a production-ready teacher salary report: model, API mapping, empty states, and test coverage. Ask clarifying questions first.”',
 'Prompt', 2, 1),

('modes', 'cursor_mode_agent', 'Agent', 'Mode 3',
 'Use Agent to implement a scoped change: read, edit, run commands, and verify.\n\nMalayalam: നിർവചിത സ്കോപ്പുള്ള മാറ്റം നടപ്പിലാക്കാൻ Agent ഉപയോഗിക്കുക: വായിക്കുക, എഡിറ്റ് ചെയ്യുക, കമാൻഡ് ഓടിക്കുക, പരിശോധിക്കുക.',
 'The contractor executing an approved drawing.',
 'അംഗീകരിച്ച ഡ്രോയിംഗ് നടപ്പിലാക്കുന്ന കോൺട്രാക്ടർ.',
 'Scoped implementation with known files and acceptance criteria.',
 'Unscoped “make it better” requests.',
 '“Make the app better.”',
 '“In teacher_salary_report_model.dart, add a null-safe totalAmount getter and cover it with a unit test. Do not refactor unrelated files.”',
 'Prompt', 3, 1),

('modes', 'cursor_mode_debug', 'Debug', 'Mode 4',
 'Use Debug when the defect is hard to reproduce. Form hypotheses, instrument, capture runtime evidence, then apply a minimal fix.\n\nMalayalam: പുനരാവർത്തിക്കാൻ ബുദ്ധിമുട്ടുള്ള ബഗുകൾക്ക് Debug ഉപയോഗിക്കുക. അനുമാനങ്ങൾ രൂപപ്പെടുത്തി ലോഗ് ഇട്ട് റൺടൈം തെളിവ് ശേഖരിച്ച ശേഷം മാത്രം ചെറിയ ഫിക്സ് നൽകുക.',
 'A diagnostic inspection before replacing parts.',
 'ഭാഗങ്ങൾ മാറ്റുന്നതിന് മുമ്പുള്ള ഡയഗ്നോസ്റ്റിക് പരിശോധന.',
 'Hard-to-reproduce crashes needing runtime evidence.',
 'Obvious typos or one-line fixes you can already prove.',
 'Guess-editing three files for a crash you cannot reproduce',
 '“Payments page crashes on salary month open. Hypothesize, add logs, and fix only the proven cause.”',
 'Prompt', 4, 1),

('modes', 'cursor_mode_multitask', 'Multitask', 'Mode 5',
 'Use Multitask only for independent workstreams that do not share the same files. Sequential Agent remains the default for dependent changes.\n\nMalayalam: ഒരേ ഫയലുകൾ പങ്കിടാത്ത സ്വതന്ത്ര ജോലികൾക്ക് മാത്രം Multitask ഉപയോഗിക്കുക. ആശ്രിത മാറ്റങ്ങൾക്ക് സാധാരണ Agent തന്നെ മതി.',
 'Three crews in three separate rooms; never two crews on the same wall.',
 'മൂന്ന് മുറികളിൽ മൂന്ന് ക്രൂകൾ; ഒരേ മതിലിൽ രണ്ട് ക്രൂകളല്ല.',
 'Independent streams with no shared files.',
 'Tightly coupled model + API + UI for one feature.',
 'Parallelising model + API + UI for one tightly coupled feature',
 '“In parallel: (1) unit tests for the salary model, (2) empty-state UI on the salary card, (3) copy fixes on the payments page.”',
 'Prompt', 5, 1);

-- ── Commands ───────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('commands', 'cursor_cmd_goal', '/goal', 'Command 1',
 'Promote a request into a durable objective. The agent continues until the outcome is complete, not until the first patch lands.\n\nMalayalam: ഒരു അഭ്യർത്ഥനയെ നീണ്ട ലക്ഷ്യമാക്കി മാറ്റുക. ആദ്യ പാച്ച് കഴിഞ്ഞാൽ നിൽക്കരുത്; ഫലം പൂർത്തിയാകും വരെ തുടരണം.',
 'Assign an outcome, not a single task slip.',
 'ഒരു ടാസ്ക് സ്ലിപ്പല്ല; ഒരു ഫലം നൽകുക.',
 'Multi-step outcomes that must stay active until verified complete.',
 'One-line patches with a single acceptance check.',
 '“Fix this button.”',
 '/goal Make the teacher salary report production-ready: no crash, empty states, and passing tests.',
 'Prompt', 1, 1),

('commands', 'cursor_cmd_side', '/side and /btw', 'Command 2',
 'Open a side thread for a clarifying question without interrupting the main assignment. Side chats inherit parent context; they are not forks.\n\nMalayalam: മെയിൻ ജോലി നിർത്താതെ ഒരു വ്യക്തത ചോദ്യം സൈഡ് ത്രെഡിൽ ചോദിക്കുക. സൈഡ് ചാറ്റ് പേരന്റ് കോൺടെക്സ്റ്റ് ഉപയോഗിക്കുന്നു; അത് ഫോർക്ക് അല്ല.',
 'A side briefing while the main assignment continues.',
 'മെയിൻ അസൈൻമെന്റ് തുടരുമ്പോഴുള്ള സൈഡ് ബ്രീഫിംഗ്.',
 'Clarifying questions that must not divert the main agent.',
 'Research tangents that belong on the main thread.',
 'Diverting the main agent into a research tangent',
 '/side Where is totalAmount derived in teacher_salary_report_model.dart? Explain only.',
 'Prompt', 2, 1),

('commands', 'cursor_cmd_loop', '/loop', 'Command 3',
 'Repeat a verification prompt on an interval for long-running work (tests, CI, regressions).\n\nMalayalam: ദീർഘമായ ജോലികളിൽ ടെസ്റ്റ്/CI/റിഗ്രഷൻ പോലുള്ള പരിശോധന ഒരു ഇടവേളയിൽ ആവർത്തിക്കുക.',
 'Scheduled site inspection, not a one-time walkthrough.',
 'ഒറ്റത്തവണ നടത്തലല്ല; ഷെഡ്യൂൾ ചെയ്ത സൈറ്റ് ഇൻസ്പെക്ഷൻ.',
 'Long-running tests, CI, or regression watches.',
 'One-shot questions answered in a single reply.',
 NULL,
 '/loop 5m Confirm salary tests still pass and the page does not crash on reload.',
 'Prompt', 3, 1),

('commands', 'cursor_cmd_in_cloud', '/in-cloud', 'Command 4',
 'Hand long-running or isolating work to a Cloud Agent so local context stays free.\n\nMalayalam: ദീർഘമായ അല്ലെങ്കിൽ ഒറ്റപ്പെടുത്തേണ്ട ജോലി Cloud Agent-ന് നൽകുക; ലോക്കൽ സെഷൻ മറ്റ് പണിക്ക് ഫ്രീ ആയി നിലനിർത്തുക.',
 'Send a crew to a separate site; keep the main office clear.',
 'ഒരു ക്രൂവിനെ മറ്റൊരു സൈറ്റിലേക്ക് അയയ്ക്കുക; മെയിൻ ഓഫീസ് ഫ്രീ ആയി നിലനിർത്തുക.',
 'Long-running or isolating investigations that should not block local work.',
 'Quick local edits you need to review immediately.',
 NULL,
 '/in-cloud Investigate CI failures on the salary branch and open a focused fix.',
 'Prompt', 4, 1),

('commands', 'cursor_cmd_worktree', '/worktree, /apply-worktree, /delete-worktree', 'Command 5',
 'Run risky experiments in an isolated Git checkout. Apply only the accepted result; delete the rest.\n\nMalayalam: റിസ്‌കുള്ള പരീക്ഷണങ്ങൾ isolated Git checkout-ൽ നടത്തുക. അംഗീകരിച്ച ഫലം മാത്രം മെയിനിലേക്ക് മാറ്റുക; ബാക്കി നീക്കം ചെയ്യുക.',
 'A full-scale mock-up beside the live building.',
 'ലൈവ് കെട്ടിടത്തിന് അരികിലുള്ള പൂർണ്ണ സ്കെയിൽ മോക്ക്-അപ്പ്.',
 'Risky layout or architecture experiments.',
 'Trivial edits that belong on the main checkout.',
 NULL,
 '/worktree Prototype a safer salary card layout without touching the main checkout.',
 'Prompt', 5, 1),

('commands', 'cursor_cmd_best_of_n', '/best-of-n', 'Command 6',
 'Compare the same prompt across models in isolated worktrees, then promote a single winner.\n\nMalayalam: ഒരേ പ്രോംപ്റ്റ് ഒന്നിലധികം മോഡലുകളിൽ isolated worktree-കളിൽ താരതമ്യം ചെയ്ത് ഒരു വിജയി മാത്രം സ്വീകരിക്കുക.',
 'Competitive bids; award one contract.',
 'മത്സര ബിഡുകൾ; ഒരു കോൺട്രാക്ട് മാത്രം അനുവദിക്കുക.',
 'High-stakes fixes where model comparison is worth the cost.',
 'Routine edits where one model is enough.',
 NULL,
 '/best-of-n composer,gpt,sonnet Fix the salary month crash with the smallest correct diff.',
 'Prompt', 6, 1);

-- ── Skills ─────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('skills', 'cursor_skill_review', '/review', 'Skill 1',
 'Run the appropriate review agent before merge.\n\nMalayalam: മെർജ് ചെയ്യുന്നതിന് മുമ്പ് ഉചിതമായ റിവ്യൂ ഏജന്റ് ഓടിക്കുക.',
 NULL, NULL,
 'Before merging any non-trivial branch.',
 'Draft spikes that will be discarded.',
 NULL, NULL, 'Prompt', 1, 1),

('skills', 'cursor_skill_review_bugbot', '/review-bugbot', 'Skill 2',
 'Review the branch diff for likely bugs and regressions before push.\n\nMalayalam: പുഷ് ചെയ്യുന്നതിന് മുമ്പ് ബ്രാഞ്ച് ഡിഫിൽ സാധ്യതയുള്ള ബഗുകളും റിഗ്രഷനുകളും പരിശോധിക്കുക.',
 NULL, NULL,
 'Before push when the diff touches product logic.',
 'Docs-only or comment-only changes.',
 NULL,
 '/review-bugbot Review uncommitted salary-report changes for regressions.',
 'Prompt', 2, 1),

('skills', 'cursor_skill_review_security', '/review-security', 'Skill 3',
 'Run a security review on auth, payments, secrets, and permission changes.\n\nMalayalam: ഓത്ത്, പേയ്‌മെന്റ്, സീക്രട്ട്, പെർമിഷൻ മാറ്റങ്ങളിൽ സെക്യൂരിറ്റി റിവ്യൂ നിർബന്ധമാണ്.',
 NULL, NULL,
 'Auth, payments, secrets, or permission diffs.',
 'Pure UI copy or layout tweaks with no auth surface.',
 NULL, NULL, 'Prompt', 3, 1),

('skills', 'cursor_skill_create_rule', '/create-rule', 'Skill 4',
 'Capture a durable project standard in `.cursor/rules` instead of repeating it in chat.\n\nMalayalam: ആവർത്തിച്ച് ചാറ്റിൽ പറയുന്നതിന് പകരം സ്ഥിരമായ സ്റ്റാൻഡേർഡ് `.cursor/rules`-ൽ രേഖപ്പെടുത്തുക.',
 NULL, NULL,
 'Standards you repeat more than once across chats.',
 'One-off instructions for a single ticket.',
 NULL,
 '/create-rule Flutter widgets must reset form state on modal close. Always apply to lib/Features/**',
 'Prompt', 4, 1),

('skills', 'cursor_skill_create_skill', '/create-skill', 'Skill 5',
 'Package a repeatable BugRicer/Flutter workflow as a skill when the same playbook is used more than twice.\n\nMalayalam: ഒരേ പ്ലേബുക്ക് രണ്ടിലധികം തവണ ഉപയോഗിക്കുകയാണെങ്കിൽ അത് skill ആയി പാക്കേജ് ചെയ്യുക.',
 NULL, NULL,
 'Playbooks reused more than twice.',
 'Ad-hoc one-time procedures.',
 NULL, NULL, 'Prompt', 5, 1),

('skills', 'cursor_skill_autopilot', '/autopilot', 'Skill 6',
 'Delegate PR follow-up (review comments, conflicts, failing checks) only after the branch is already scoped and pushed.\n\nMalayalam: സ്കോപ്പ് ഉറച്ച് പുഷ് ചെയ്ത ശേഷം മാത്രം PR ഫോളോ-അപ്പ് autopilot-ന് നൽകുക.',
 NULL, NULL,
 'Scoped, pushed PRs needing comment/CI follow-up.',
 'Unscoped work that has not been pushed yet.',
 NULL, NULL, 'Prompt', 6, 1);

-- ── Workflow ───────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('workflow', 'cursor_wf_mode_model', 'Mode and model are independent', 'Workflow 1',
 'Choose mode for permission (read vs write vs plan) and model for reasoning cost. Do not treat Composer as a mode.\n\nMalayalam: മോഡ് അനുമതി നിർണയിക്കുന്നു (വായന/എഴുത്ത്/പ്ലാൻ); മോഡൽ യുക്തിയുടെ ആഴവും ചെലവും നിർണയിക്കുന്നു. Composer ഒരു മോഡ് അല്ല.',
 NULL, NULL,
 'Every session: pick mode and model separately.',
 NULL,
 NULL,
 'Plan with a stronger model, implement with a faster coding model, review before merge.',
 'Checklist', 1, 1),

('workflow', 'cursor_wf_scope', 'Scope before autonomy', 'Workflow 2',
 'Name files, acceptance criteria, and out-of-scope items. Unscoped autonomy creates noisy diffs.\n\nMalayalam: ഫയലുകൾ, സ്വീകാര്യതാ മാനദണ്ഡങ്ങൾ, പുറത്തുള്ള ഇനങ്ങൾ എന്നിവ വ്യക്തമാക്കുക. സ്കോപ്പില്ലാത്ത സ്വയംഭരണം അനാവശ്യ ഡിഫുകൾ ഉണ്ടാക്കും.',
 NULL, NULL,
 'Before giving Agent autonomy on a feature.',
 NULL,
 '“Handle salary.”',
 '“Update only Payment salary cards and the report model. Do not change Wallet or Dashboard.”',
 'Prompt', 2, 1),

('workflow', 'cursor_wf_ask_then_agent', 'Inspect, then mutate', 'Workflow 3',
 'For unfamiliar code, Ask first. Switch to Agent only after the edit surface is known.\n\nMalayalam: അപരിചിത കോഡിൽ ആദ്യം Ask. എഡിറ്റ് ചെയ്യേണ്ട സ്ഥലം ഉറപ്പായ ശേഷം മാത്രം Agent.',
 NULL, NULL,
 'Unfamiliar modules before the first edit.',
 'Code you already own end-to-end.',
 NULL, NULL, 'Prompt', 3, 1),

('workflow', 'cursor_wf_rules', 'Rules vs Tab', 'Workflow 4',
 'Project rules and AGENTS.md guide Agent/Chat. They do not control Tab or inline edit. Keep secrets out via `.cursorignore`.\n\nMalayalam: പ്രോജക്റ്റ് റൂളുകളും AGENTS.md-യും Agent/Chat-നെ നയിക്കുന്നു. Tab-ഇലോ inline edit-ലോ അവ ബാധകമല്ല. സീക്രട്ടുകൾ `.cursorignore` വഴി ഒഴിവാക്കുക.',
 NULL, NULL,
 'When writing or reviewing project rules.',
 NULL,
 NULL, NULL, 'Prompt', 4, 1),

('workflow', 'cursor_wf_checkpoints', 'Checkpoints are not Git', 'Workflow 5',
 'Use Agent checkpoints to revert a bad agent turn. Use Git for durable history.\n\nMalayalam: മോശം ഏജന്റ് ടേൺ പഴയപടിയാക്കാൻ checkpoint ഉപയോഗിക്കുക. സ്ഥിരമായ ചരിത്രത്തിന് Git തന്നെ വേണം.',
 NULL, NULL,
 'Immediately after a bad agent turn.',
 'Long-term history and collaboration (use Git).',
 NULL, NULL, 'Prompt', 5, 1);

-- ── Review ─────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('review', 'cursor_rev_diff_hygiene', 'Diff hygiene', 'Review 1',
 'Reject tips-driven changes that touch unrelated files, leave debug logs, or skip empty/error states.\n\nMalayalam: ബന്ധമില്ലാത്ത ഫയലുകൾ തൊടുന്ന, ഡീബഗ് ലോഗ് ഇട്ട, എംപ്റ്റി/എറർ സ്റ്റേറ്റ് ഒഴിവാക്കുന്ന മാറ്റങ്ങൾ നിരസിക്കുക.',
 NULL, NULL,
 'Every PR review before approve.',
 NULL,
 NULL, NULL, 'Checklist', 1, 1),

('review', 'cursor_rev_verify', 'Verify in the running product', 'Review 2',
 'After Agent edits, run the affected flow. A green compile is not verification.\n\nMalayalam: ഏജന്റ് എഡിറ്റിന് ശേഷം ബന്ധപ്പെട്ട യൂസർ ഫ്ലോ ഓടിക്കുക. കംപൈൽ ഗ്രീൻ ആയതുകൊണ്ട് മാത്രം പര്യാപ്തമല്ല.',
 NULL, NULL,
 'After every Agent implementation turn.',
 NULL,
 NULL,
 'Open the salary page, empty state, error state, and one successful load.',
 'Checklist', 2, 1),

('review', 'cursor_rev_bugricer_codo', 'CODO still governs', 'Review 3',
 'Cursor Tips never override Common CODO. Hard state reset, confirmation modals, permission checks, and bilingual fields remain mandatory.\n\nMalayalam: Cursor Tips Common CODO-യെ മറികടക്കരുത്. സ്റ്റേറ്റ് റീസെറ്റ്, കൺഫർമേഷൻ മോഡൽ, പെർമിഷൻ, ദ്വിഭാഷാ ഫീൽഡുകൾ എന്നിവ നിർബന്ധമായും പാലിക്കണം.',
 NULL, NULL,
 'Whenever Cursor Tips guidance is applied to BugRicer product work.',
 NULL,
 NULL, NULL, 'Checklist', 3, 1);

-- ── Permissions (skip gracefully if permissions tables are absent) ─────────
SET @has_permissions := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'
);
SET @has_role_permissions := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions'
);
SET @has_roles := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'
);

SET @sql := IF(@has_permissions > 0,
  'INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
   SELECT ''CURSOR_TIPS_VIEW'', ''View Cursor Tips'', ''CURSOR_TIPS'', ''global'', NOW()
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = ''CURSOR_TIPS_VIEW'')',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_permissions > 0,
  'INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
   SELECT ''CURSOR_TIPS_MANAGE'', ''Manage Cursor Tips'', ''CURSOR_TIPS'', ''global'', NOW()
   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `permission_key` = ''CURSOR_TIPS_MANAGE'')',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_permissions > 0 AND @has_role_permissions > 0,
  'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
   SELECT 1, p.id, NOW()
   FROM `permissions` p
   WHERE p.permission_key IN (''CURSOR_TIPS_VIEW'', ''CURSOR_TIPS_MANAGE'')
   AND NOT EXISTS (
     SELECT 1 FROM `role_permissions` rp
     WHERE rp.role_id = 1 AND rp.permission_id = p.id
   )',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_permissions > 0 AND @has_role_permissions > 0,
  'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
   SELECT 2, p.id, NOW()
   FROM `permissions` p
   WHERE p.permission_key = ''CURSOR_TIPS_VIEW''
   AND NOT EXISTS (
     SELECT 1 FROM `role_permissions` rp
     WHERE rp.role_id = 2 AND rp.permission_id = p.id
   )',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_permissions > 0 AND @has_role_permissions > 0,
  'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
   SELECT 3, p.id, NOW()
   FROM `permissions` p
   WHERE p.permission_key = ''CURSOR_TIPS_VIEW''
   AND NOT EXISTS (
     SELECT 1 FROM `role_permissions` rp
     WHERE rp.role_id = 3 AND rp.permission_id = p.id
   )',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_permissions > 0 AND @has_role_permissions > 0 AND @has_roles > 0,
  'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
   SELECT r.id, p.id, NOW()
   FROM `roles` r
   CROSS JOIN `permissions` p
   WHERE LOWER(r.role_name) = ''creator''
   AND p.permission_key = ''CURSOR_TIPS_VIEW''
   AND NOT EXISTS (
     SELECT 1 FROM `role_permissions` rp
     WHERE rp.role_id = r.id AND rp.permission_id = p.id
   )',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
