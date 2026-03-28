-- Adds soft-deactivation flag for users. Run once on your BugRicer database.
-- Existing rows get account_active = 1. Login and validateToken reject account_active = 0.

ALTER TABLE users
  ADD COLUMN account_active TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1 = active, 0 = deactivated by admin';
