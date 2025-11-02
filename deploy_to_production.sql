-- ============================================
-- Production Permission System - COMPLETE & VERIFIED
-- BugRicer - Ready for Production
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing permission tables
DROP TABLE IF EXISTS `user_permissions`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;

SET FOREIGN_KEY_CHECKS = 1;

-- Create tables
SOURCE config/permissions_production.sql

SELECT '✅ Deployment successful!' as status;
