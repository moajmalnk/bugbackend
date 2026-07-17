-- Domains, target platforms, app store URLs, and GitHub repos for projects

ALTER TABLE `projects`
  ADD COLUMN `frontend_domain` VARCHAR(500) DEFAULT NULL AFTER `reference_sites_or_themes`,
  ADD COLUMN `backend_domain` VARCHAR(500) DEFAULT NULL AFTER `frontend_domain`,
  ADD COLUMN `vercel_domain` VARCHAR(500) DEFAULT NULL AFTER `backend_domain`,
  ADD COLUMN `platforms` VARCHAR(100) DEFAULT NULL AFTER `vercel_domain`,
  ADD COLUMN `app_url_ios` VARCHAR(500) DEFAULT NULL AFTER `platforms`,
  ADD COLUMN `app_url_android` VARCHAR(500) DEFAULT NULL AFTER `app_url_ios`,
  ADD COLUMN `testflight_url` VARCHAR(500) DEFAULT NULL AFTER `app_url_android`,
  ADD COLUMN `github_frontend` VARCHAR(500) DEFAULT NULL AFTER `testflight_url`,
  ADD COLUMN `github_backend` VARCHAR(500) DEFAULT NULL AFTER `github_frontend`,
  ADD COLUMN `github_app` VARCHAR(500) DEFAULT NULL AFTER `github_backend`;
