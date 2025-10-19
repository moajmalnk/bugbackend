# Fix Voice Message Paths in Production

## Problem
Old voice messages in the database have paths pointing to `voice_messages` folder instead of `voice_notes`, causing 504 errors when trying to play them.

## Solution
Run the migration script to update all voice message paths in the database.

## Steps to Fix in Production

### Option 1: Run PHP Migration Script (Recommended)
```bash
# SSH into production server
cd /path/to/BugRicer/backend/config

# Run the migration
php fix_voice_paths.php
```

### Option 2: Run SQL Migration Directly
```bash
# SSH into production server
mysql -u your_username -p your_database_name < /path/to/BugRicer/backend/config/fix_voice_paths.sql
```

### Option 3: Run via MySQL Command Line
```sql
-- Connect to MySQL
mysql -u your_username -p your_database_name

-- Run the update
UPDATE chat_messages 
SET voice_file_path = REPLACE(voice_file_path, 'voice_messages', 'voice_notes')
WHERE voice_file_path LIKE '%voice_messages%';

-- Verify the fix
SELECT COUNT(*) as updated_count 
FROM chat_messages 
WHERE voice_file_path LIKE '%voice_notes%' AND message_type = 'voice';
```

## What This Does
- Updates all `voice_file_path` values in the `chat_messages` table
- Replaces `voice_messages` with `voice_notes` in the path
- Only affects messages where the path contains `voice_messages`

## Verify the Fix
After running the migration:
1. Check the count of updated messages
2. Try playing a voice message in the app
3. Check that no more 504 errors occur

## No Downtime Required
This migration can be run while the application is running. It only updates database paths and doesn't affect the actual audio files.

## Rollback (if needed)
If you need to rollback:
```sql
UPDATE chat_messages 
SET voice_file_path = REPLACE(voice_file_path, 'voice_notes', 'voice_messages')
WHERE voice_file_path LIKE '%voice_notes%';
```

## Additional Fixes Deployed
1. Enhanced error logging in `send_message.php` for better debugging
2. Table existence check for `starred_messages` in `get_messages.php`
3. Improved error handling in message info API

## Testing
After deployment and migration:
- Send a new voice message ✅
- Play an old voice message ✅
- Check production logs for any remaining errors ✅

