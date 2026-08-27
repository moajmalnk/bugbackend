-- Why: Creators often keep a Drive folder AND an uploaded file (or thumbnail).
-- asset_source must allow link, upload, or both — not a forced exclusive toggle.
ALTER TABLE `creative_assets`
  MODIFY COLUMN `asset_source` ENUM('link', 'upload', 'both') NOT NULL DEFAULT 'link';
