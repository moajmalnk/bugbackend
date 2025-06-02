-- Fix existing "fixed" bugs that don't have updated_by set
-- This will allow the statistics to show correct fix counts

-- ================================================================
-- UPDATE EXISTING FIXED BUGS TO SET updated_by
-- ================================================================

-- Option 1: Set updated_by to reported_by for existing fixed bugs
-- (Assumes the person who reported the bug also fixed it - reasonable for existing data)
UPDATE bugs 
SET updated_by = reported_by 
WHERE status = 'fixed' 
AND updated_by IS NULL;

-- Option 2: If you want to set a specific admin user as the fixer
-- Replace 'ADMIN_USER_ID' with the actual admin user ID
-- UPDATE bugs 
-- SET updated_by = 1  -- Replace 1 with actual admin user ID
-- WHERE status = 'fixed' 
-- AND updated_by IS NULL;

-- Verify the changes
SELECT 
    'Before fix' as status,
    COUNT(*) as total_fixed_bugs,
    COUNT(updated_by) as fixed_bugs_with_updated_by
FROM bugs 
WHERE status = 'fixed';

-- Show sample of updated records
SELECT 
    id,
    title,
    status,
    reported_by,
    updated_by,
    created_at,
    updated_at
FROM bugs 
WHERE status = 'fixed' 
LIMIT 5;

-- Test the statistics query that's used in the backend
SELECT 
    u.id as user_id,
    u.username,
    u.role,
    COUNT(b.id) as total_fixes
FROM users u
LEFT JOIN bugs b ON b.updated_by = u.id AND b.status = 'fixed'
GROUP BY u.id, u.username, u.role
ORDER BY total_fixes DESC; 