-- Attach Kotta Deals Google Drive folder to matching BugCreative assets (safe to re-run).
-- Folder: https://drive.google.com/drive/folders/1kYqAYY0UBJoilCJs4jOmg5ncUuiVx-NB?usp=sharing

SET @kotta_drive := 'https://drive.google.com/drive/folders/1kYqAYY0UBJoilCJs4jOmg5ncUuiVx-NB?usp=sharing';

UPDATE creative_assets
SET
  drive_link = @kotta_drive,
  asset_source = 'link',
  updated_at = CURRENT_TIMESTAMP
WHERE
  (
    title LIKE '%Kotta Deals%'
    OR title LIKE '%kotta deals%'
    OR hook_content LIKE '%Kotta Deals%'
    OR hook_content LIKE '%Design kit kotta%'
  )
  AND (drive_link IS NULL OR drive_link = '' OR drive_link <> @kotta_drive);

SELECT id, title, drive_link, status
FROM creative_assets
WHERE title LIKE '%Kotta%' OR title LIKE '%kotta%' OR hook_content LIKE '%kotta%'
ORDER BY updated_at DESC;
