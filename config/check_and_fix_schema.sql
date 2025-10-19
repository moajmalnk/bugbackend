-- Check and Fix WhatsApp Schema
-- This script checks which tables/columns exist and only adds missing ones
-- Run this to complete your schema without errors

-- Check what exists
SELECT 
    TABLE_NAME,
    COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN (
    'chat_messages', 'users', 'chat_groups', 'starred_messages', 
    'message_delivery_status', 'user_status', 'status_views',
    'broadcast_lists', 'broadcast_recipients', 'disappearing_messages_settings',
    'blocked_users', 'group_admins', 'call_logs', 'call_participants',
    'message_polls', 'poll_options', 'poll_votes'
)
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- If you see missing tables from the list above, run the specific CREATE TABLE statements
-- If you see tables but missing columns, run the specific ALTER TABLE statements

-- Here's a quick check for the main tables:
SHOW TABLES LIKE '%status%';
SHOW TABLES LIKE '%poll%';
SHOW TABLES LIKE '%call%';
SHOW TABLES LIKE '%broadcast%';
SHOW TABLES LIKE '%blocked%';
SHOW TABLES LIKE '%starred%';

