-- =============================================================================
-- BugRicer Migration 113 — Cursor Tips: cost and context habits
-- Safe to re-run. Upserts by (phase, tip_key); requires 103_cursor_tips.sql.
-- Model prices reflect the Cursor model menu at time of writing (Sep 2026).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('workflow', 'cursor_wf_multiple_models_off', 'Leave Use Multiple Models off', 'Workflow 6',
 'Keep Use Multiple Models off, and do not run several agents on the same task. Each extra model or agent re-reads the whole chat and bills separately, so one task can cost two to four times as much for one answer you keep.\n\nMalayalam: Use Multiple Models ഓഫ് ആക്കി വയ്ക്കുക; ഒരേ ടാസ്കിന് പല ഏജന്റുകൾ ഓടിക്കരുത്. ഓരോ അധിക മോഡലും ഏജന്റും മുഴുവൻ ചാറ്റും വീണ്ടും വായിച്ച് പ്രത്യേകം ചാർജ് ചെയ്യും.',
 'Hiring four contractors to quote the same small repair.',
 'ഒരു ചെറിയ റിപ്പയറിന് നാല് കോൺട്രാക്ടർമാരെ വിളിക്കുന്നത് പോലെ.',
 'Always, unless you are deliberately comparing models on a high-stakes fix.',
 'Routine edits, bug fixes, and anything a single model can finish.',
 'Use Multiple Models on, three agents fixing the same salary crash in parallel',
 'One agent, one model, one task: “Fix the null crash in teacher_salary_report_model.dart. Done when the salary page opens with no data.”',
 'Prompt', 6, 1),

('workflow', 'cursor_wf_premium_models', 'Save premium models for hard design questions', 'Workflow 7',
 'Opus, Fable, GPT-5.6 Sol, and Sonnet High give stronger answers on hard design questions, but they drain the small usage pool fast. Claude Fable 5.1 is the most expensive option in the menu ($10 per million input tokens, $50 per million output tokens). Use them for architecture and tricky debugging, and a faster model for routine edits. Check what is left under Dashboard → Spending.\n\nMalayalam: Opus, Fable, GPT-5.6 Sol, Sonnet High എന്നിവ കഠിനമായ ഡിസൈൻ ചോദ്യങ്ങൾക്ക് മികച്ചതാണ്, പക്ഷേ ചെറിയ യൂസേജ് പൂൾ വേഗം തീർക്കും. Claude Fable 5.1 ആണ് ഏറ്റവും ചെലവേറിയത് ($10 ഇൻപുട്ട് / $50 ഔട്ട്പുട്ട്). ബാക്കി എത്രയെന്ന് Dashboard → Spending-ൽ നോക്കുക.',
 'Calling the structural engineer for design, not for painting walls.',
 'പെയിന്റിംഗിനല്ല, ഡിസൈനിനാണ് സ്ട്രക്ചറൽ എഞ്ചിനീയറെ വിളിക്കേണ്ടത്.',
 'Architecture decisions, ambiguous multi-file plans, hard-to-find bugs.',
 'Renames, copy fixes, small UI tweaks, and well-scoped one-file edits.',
 'Claude Fable 5.1 to rename a button label',
 'Plan the BugAssets tools schema with a premium model; implement the plan with a faster coding model.',
 'Prompt', 7, 1),

('workflow', 'cursor_wf_one_task_per_chat', 'One task per chat', 'Workflow 8',
 'Every reply resends the whole thread, so a long chat makes each new message more expensive and less focused. Start a new chat when the job changes, and carry over only a short summary if you need it.\n\nMalayalam: ഓരോ മറുപടിയും മുഴുവൻ ത്രെഡും വീണ്ടും അയയ്ക്കുന്നു. ജോലി മാറുമ്പോൾ പുതിയ ചാറ്റ് തുടങ്ങുക; ആവശ്യമെങ്കിൽ ചെറിയ സംഗ്രഹം മാത്രം കൊണ്ടുപോകുക.',
 'Opening a fresh job ticket instead of stapling new work to an old one.',
 'പഴയ ടിക്കറ്റിൽ പുതിയ ജോലി ചേർക്കാതെ പുതിയ ജോബ് ടിക്കറ്റ് തുറക്കുന്നത് പോലെ.',
 'Whenever the file set, feature, or goal changes.',
 'Follow-up fixes on the exact change the current chat just made.',
 'Mailbox migration, then tab CSS, then birthday wishes, all in one chat',
 'New chat: “Fix BugAssets tab wrapping in BottomSheetTabs.tsx.”',
 'Prompt', 8, 1),

('workflow', 'cursor_wf_name_files', 'Name the files to touch', 'Workflow 9',
 'Mention the specific files the change needs. Skip @codebase and whole folders; they pull in large amounts of unrelated code and let the agent wander.\n\nMalayalam: മാറ്റേണ്ട ഫയലുകൾ പേരെടുത്ത് പറയുക. @codebase-ഉം മുഴുവൻ ഫോൾഡറുകളും ഒഴിവാക്കുക; അവ ബന്ധമില്ലാത്ത കോഡ് കൊണ്ടുവരും.',
 'Giving the plumber the room number, not the whole building plan.',
 'പ്ലംബറിന് മുഴുവൻ കെട്ടിട പ്ലാനല്ല, മുറി നമ്പർ നൽകുക.',
 'Any change where you already know the edit surface.',
 'Early exploration of unfamiliar code (use Ask mode for that first).',
 '@codebase fix the renewals count',
 '@AssetsSummaryController.php @asset_renewals.php Include premium tools in the 30-day renewals count.',
 'Prompt', 9, 1),

('workflow', 'cursor_wf_cursorignore', 'Add a .cursorignore', 'Workflow 10',
 'Exclude node_modules, build output, lockfiles, and logs with a `.cursorignore` at the repo root. Indexing and search then stay on real source code, and secrets stay out of context.\n\nMalayalam: റിപ്പോ റൂട്ടിൽ `.cursorignore` ചേർത്ത് node_modules, ബിൽഡ് ഔട്ട്പുട്ട്, ലോക്ക്ഫയലുകൾ, ലോഗുകൾ എന്നിവ ഒഴിവാക്കുക.',
 'Locking the storeroom so visitors only tour the finished rooms.',
 'സന്ദർശകർ പൂർത്തിയായ മുറികൾ മാത്രം കാണാൻ സ്റ്റോർറൂം പൂട്ടുന്നത് പോലെ.',
 'Once per repo, and whenever a new generated folder appears.',
 NULL,
 'No ignore file; agent search returns hits from dist/ and package-lock.json',
 'node_modules/\ndist/\nbuild/\n*.log\npackage-lock.json\ncomposer.lock\n.env\n.env.*',
 'Text', 10, 1),

('workflow', 'cursor_wf_failing_line', 'Paste the failing line, not the whole log', 'Workflow 11',
 'Paste the error message and the line it points to. A full test or build log is mostly noise and costs tokens on every later reply in the chat.\n\nMalayalam: പൂർണ്ണ ലോഗ് അല്ല, പരാജയപ്പെട്ട വരിയും എറർ മെസേജും മാത്രം പേസ്റ്റ് ചെയ്യുക.',
 'Telling the mechanic which warning light is on, not reading the whole manual aloud.',
 'മുഴുവൻ മാനുവൽ വായിക്കാതെ ഏത് വാണിംഗ് ലൈറ്റ് കത്തുന്നു എന്ന് മെക്കാനിക്കിനോട് പറയുക.',
 'Test failures, build errors, SQL errors, console exceptions.',
 'When the failure genuinely depends on earlier log context (then paste only that section).',
 'Pasting 400 lines of phpMyAdmin output',
 '#1005 Can''t create table assets_tool_seats (errno: 150 "Foreign key constraint is incorrectly formed") in 109_assets_premium_tools.sql',
 'Prompt', 11, 1),

('workflow', 'cursor_wf_done_condition', 'Short prompt, clear done condition', 'Workflow 12',
 'Write a short prompt that says what "done" looks like, so the agent stops when it gets there instead of exploring further.\n\nMalayalam: "പൂർത്തിയായി" എന്നതിന്റെ അർത്ഥം വ്യക്തമാക്കുന്ന ചെറിയ പ്രോംപ്റ്റ് എഴുതുക; അപ്പോൾ ഏജന്റ് അനാവശ്യമായി പര്യവേക്ഷണം ചെയ്യാതെ നിർത്തും.',
 'A work order with a sign-off line.',
 'സൈൻ-ഓഫ് വരിയുള്ള വർക്ക് ഓർഡർ.',
 'Every Agent request.',
 NULL,
 '“Improve the BugAssets page.”',
 '“Fix Premium Tools tab overlapping the search panel in BugAssets.tsx. Done when all six tabs fit on one row at xl and wrap cleanly below.”',
 'Prompt', 12, 1)
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
