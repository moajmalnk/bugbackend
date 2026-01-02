-- Add role column to announcements table for role-based access control
-- This allows announcements to be targeted to specific roles (all, admins, developers, testers)

ALTER TABLE `announcements` 
ADD COLUMN IF NOT EXISTS `role` VARCHAR(100) DEFAULT 'all' COMMENT 'Role access: all, admins, developers, testers (comma-separated for multiple)' AFTER `expiry_date`;

-- Add index for role filtering
ALTER TABLE `announcements` 
ADD INDEX IF NOT EXISTS `idx_role` (`role`);

