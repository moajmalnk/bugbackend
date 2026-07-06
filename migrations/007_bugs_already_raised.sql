-- Track whether reporter marked the bug as already raised elsewhere (apply once on your DB)
ALTER TABLE `bugs`
ADD COLUMN `already_raised` TINYINT(1) NOT NULL DEFAULT 0
AFTER `actual_result`;
