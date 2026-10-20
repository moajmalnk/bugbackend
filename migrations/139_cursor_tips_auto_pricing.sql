-- =============================================================================
-- BugRicer Migration 139 — Cursor Tips: Auto model-based pricing (Aug 2026)
-- Source: Cursor Team notice effective 24 Aug 2026 — Auto leaves flat-rate billing
-- and charges the rate of the model each request is routed to; included usage
-- limits increase. Safe to re-run (upsert by tip_key).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

-- Refresh Workflow 7: Auto is no longer a flat "Cost" bargain for small bugs.
INSERT INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('workflow', 'cursor_wf_premium_models', 'Composer 2.5 for small field bugs', 'Workflow 7',
 'For a simple field bug use Composer 2.5 with Fast off. Do not pick Auto expecting a cheap flat rate—from 24 Aug 2026 Auto bills the rate of whichever model it routes to, and most requests cost more than the old flat Auto rate. Do not use Grok, Fast, Claude, or GPT for a simple field bug. Save stronger models (and Auto on hard work) for architecture and tricky debugging.\n\nMalayalam: ലളിതമായ ഫീൽഡ് ബഗിന് Composer 2.5. Fast ഓഫ്. 2026 Aug 24 മുതൽ Auto flat rate അല്ല—റൂട്ട് ചെയ്യുന്ന മോഡലിന്റെ നിരക്ക്; മിക്ക അഭ്യർത്ഥനകളും പഴയ Auto-യെക്കാൾ ചെലവേറിയതാണ്. Grok, Fast, Claude, GPT ലളിത ബഗിന് വേണ്ട. ശക്തമായ മോഡൽ/Auto കഠിന ഡിസൈൻ-ഡീബഗ്ഗിന് മാത്രം.',
 'Sending the senior architect to change one form label.',
 'ഒരു ഫോം ലേബൽ മാറ്റാൻ സീനിയർ ആർക്കിടെക്റ്റിനെ അയക്കുന്നത് പോലെ.',
 'One-field validation, copy, and other small scoped fixes.',
 'Architecture, ambiguous multi-file plans, and bugs you cannot locate.',
 'Auto or Claude for a one-field name validation expecting flat cheap Auto',
 'Composer 2.5, Fast off. “In ContactForm, the name field accepts letters only and rejects numbers.”',
 'Prompt', 7, 1)
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

INSERT INTO `cursor_tips`
  (`phase`, `tip_key`, `title`, `subtitle`, `description`, `analogy_en`, `analogy_ml`, `when_to_use`, `when_not_to_use`, `example_bad`, `example_good`, `example_language`, `sort_order`, `is_active`)
VALUES
('workflow', 'cursor_wf_auto_pricing', 'Auto bills the routed model (from Aug 2026)', 'Workflow 19',
 'From 24 August 2026, Cursor Auto no longer uses one flat rate. Each Auto request is billed at the rate of the model Auto routes to. How Auto chooses models is unchanged; what you pay can be higher than the old flat Auto rate for most requests. Included usage limits for Cursor Models (including Auto) were increased automatically—check Dashboard → Usage / Spending. Prefer an explicit cheaper model (for example Composer 2.5) for routine small fixes; use Auto when you want routing for harder work and accept variable cost.\n\nMalayalam: 2026 ഓഗസ്റ്റ് 24 മുതൽ Auto ഒരു flat rate അല്ല. Auto ഏത് മോഡലിലേക്ക് റൂട്ട് ചെയ്യുന്നുവോ അതിന്റെ നിരക്ക്. Auto എങ്ങനെ തിരഞ്ഞെടുക്കുന്നു എന്നത് മാറിയില്ല; മിക്ക അഭ്യർത്ഥനകളും പഴയ flat Auto-യെക്കാൾ ചെലവേറിയതാകാം. Usage limits കൂടി—Dashboard → Usage/Spending നോക്കുക. ലളിത ഫിക്സിന് Composer 2.5 പോലുള്ള വ്യക്തമായ മോഡൽ; കഠിന ജോലിക്ക് Auto (വേരിയബിൾ ചെലവ് അംഗീകരിച്ച്).',
 'A taxi meter that charges the car it actually sends, not one flat city fare.',
 'ഒരു ഫ്ലാറ്റ് നഗര നിരക്കല്ല—യഥാർത്ഥത്തിൽ വരുന്ന വാഹനത്തിന്റെ മീറ്റർ.',
 'When choosing Auto vs a named model, or reviewing the weekly Usage bill.',
 'When you already pinned Composer 2.5 (or another named model) for a small fix.',
 'Leaving Auto on for every one-line rename and wondering why the bill jumped',
 'Small field bug → Composer 2.5. Hard multi-file plan → Auto or a premium model. Check Usage weekly.',
 'Checklist', 19, 1)
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
