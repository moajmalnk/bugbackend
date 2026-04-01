-- BugBot / future AI analytics: optional JSON blob per bug (apply once on your DB)
ALTER TABLE `bugs`
ADD COLUMN `ai_metadata` JSON NULL DEFAULT NULL
AFTER `updated_by`;
