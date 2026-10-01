-- =============================================================================
-- BugRicer Migration 117 — google_login_codes (same-tab Google Sign-In)
-- =============================================================================
-- Why: Google Sign-In now uses redirect mode. Google POSTs the ID token to
-- api/auth/google-login-redirect.php, which stores a short-lived single-use
-- code (SHA-256 hash only) and redirects to the frontend with that code. The
-- frontend trades it for a JWT via api/auth/google-exchange.php, so the JWT
-- never appears in a URL.
--
-- Additive only; safe to re-run. Mirrors br_google_ensure_login_codes_table().
-- =============================================================================

CREATE TABLE IF NOT EXISTS `google_login_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash` CHAR(64) NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_login_codes_hash` (`code_hash`),
  KEY `idx_google_login_codes_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
