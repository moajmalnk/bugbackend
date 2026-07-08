-- Add reference links/theme notes field for projects

ALTER TABLE `projects`
  ADD COLUMN `reference_sites_or_themes` TEXT DEFAULT NULL AFTER `technology_stack`;
