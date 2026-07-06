-- Bug impact level rating (apply once on your DB; run after 007_bugs_already_raised.sql if present)
ALTER TABLE `bugs`
ADD COLUMN `bug_level` ENUM('normal', 'floap', 'utter_floap') NOT NULL DEFAULT 'normal'
AFTER `already_raised`;
