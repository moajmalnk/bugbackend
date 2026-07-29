-- Fix collation mismatch that breaks bug_types JOINs on Hostinger.
-- bugs.id is typically utf8mb4_unicode_ci; 032 created general_ci tables.
-- Safe to re-run.

ALTER TABLE bug_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE bug_bug_types CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
