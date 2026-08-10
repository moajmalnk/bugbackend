-- =============================================================================
-- BugRicer Migration 058 — Fix performance review table collations
-- =============================================================================
-- Fixes: SQLSTATE[HY000]: 1267 Illegal mix of collations
-- (utf8mb4_general_ci vs utf8mb4_unicode_ci) when JOINing to users.
-- Safe to re-run.
-- =============================================================================

ALTER TABLE `review_templates`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

ALTER TABLE `review_questions`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

ALTER TABLE `performance_reviews`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

ALTER TABLE `review_answers`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
