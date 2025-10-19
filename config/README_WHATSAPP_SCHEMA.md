# WhatsApp Features Schema Installation Guide

This directory contains SQL files for adding WhatsApp-like features to your messaging system.

## Files Overview

1. **`whatsapp_features_schema_no_events.sql`** ⭐ **USE THIS ONE FIRST**
   - Contains all tables, columns, indexes, and views
   - **Does NOT include automated events** (safe for phpMyAdmin import)
   - No Event Scheduler required

2. **`whatsapp_features_schema.sql`**
   - Complete version with automated cleanup events
   - Requires Event Scheduler to be enabled
   - Use only if you enable Event Scheduler

3. **`enable_event_scheduler.sql`**
   - Commands to enable MySQL Event Scheduler
   - Instructions for permanent configuration

## Installation Steps

### Step 1: Import Main Schema (No Events)

**Recommended for phpMyAdmin:**

```sql
-- In phpMyAdmin, import this file:
whatsapp_features_schema_no_events.sql
```

This will create:
- ✅ Enhanced message features (media, forwarding, starring, editing)
- ✅ User online status and profiles
- ✅ Group enhancements (pictures, archiving)
- ✅ Starred messages tracking
- ✅ Message delivery status
- ✅ User stories/status
- ✅ Broadcast lists
- ✅ Disappearing messages settings
- ✅ Blocked users
- ✅ Group admins
- ✅ Call logs
- ✅ Polls
- ✅ Full-text search
- ✅ Unread message counts view

### Step 2: (Optional) Enable Automated Cleanup

If you want automated cleanup of expired content:

#### Option A: Enable for Current Session Only

```sql
SET GLOBAL event_scheduler = ON;
```

Then run the events from `whatsapp_features_schema.sql` (lines 256-276).

#### Option B: Enable Permanently

1. Find your MySQL configuration file:
   - **XAMPP Mac**: `/Applications/XAMPP/xamppfiles/etc/my.cnf`
   - **XAMPP Windows**: `C:\xampp\mysql\bin\my.ini`
   - **Standard MySQL**: `/etc/mysql/my.cnf`

2. Add under `[mysqld]` section:
   ```ini
   [mysqld]
   event_scheduler=ON
   ```

3. Restart MySQL service

4. Run `enable_event_scheduler.sql` to verify

5. Then import the complete `whatsapp_features_schema.sql`

## What the Events Do

If enabled, these automated tasks run in the background:

1. **`cleanup_expired_status`** (runs every hour)
   - Deletes user stories/status after 24 hours
   - Keeps your database clean

2. **`update_offline_users`** (runs every minute)
   - Marks users as offline after 5 minutes of inactivity
   - Updates online status automatically

## Common Errors

### Error #1577: Event scheduler is disabled

This error means:
- ❌ Event Scheduler is disabled in MySQL
- ✅ **Solution**: Use `whatsapp_features_schema_no_events.sql` instead

### Error #1408: Event Scheduler initialization error

This error means:
- ❌ MySQL system tables for events are corrupted
- ✅ **Solution**: Use `whatsapp_features_schema_no_events.sql` instead
- ✅ **Alternative**: Use the PHP cleanup script (see below)

## PHP Cleanup Script (Alternative to Events)

Instead of MySQL events, you can use the PHP cleanup script:

**Location:** `/backend/api/messaging/cleanup_tasks.php`

### Usage Options

**Option 1: Run via Cron Job (Recommended)**
```bash
# Edit crontab
crontab -e

# Add this line (runs every hour)
0 * * * * cd /Applications/XAMPP/xamppfiles/htdocs/BugRicer/backend/api/messaging && php cleanup_tasks.php
```

**Option 2: Run via HTTP (with secret token)**
```bash
# Change the secret token in cleanup_tasks.php first!
# Then call via URL:
curl "https://bugbackend.bugricer.com/api/messaging/cleanup_tasks.php?token=your_secret_token"
```

**Option 3: Run Manually**
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/BugRicer/backend/api/messaging
php cleanup_tasks.php
```

### What the Cleanup Script Does

1. ✅ Deletes expired user stories/status (after 24 hours)
2. ✅ Marks inactive users as offline (after 5 minutes)
3. ✅ Cleans old delivery status records (after 30 days)
4. ✅ Removes old call logs (after 90 days)
5. ✅ Logs all actions to `/backend/logs/cleanup_tasks.log`

**Security Note:** Change the `$CLEANUP_SECRET` token in the script before using!

## Features Added

### Message Enhancements
- Media support (images, videos, documents, audio)
- Message forwarding
- Message starring (personal starred messages)
- Message editing
- Delivery status (sent, delivered, read, failed)

### User Features
- Online/offline status
- Last seen timestamp
- Profile pictures
- Status messages (bio)
- Privacy settings (show online status, show last seen)

### Group Features
- Group pictures
- Archive conversations
- Group admin permissions
- Disappearing messages settings

### Communication Features
- Voice/Video call logs
- Broadcast lists
- User stories/status (24-hour content)
- Message polls
- Block users

### Search & Performance
- Full-text search on messages
- Optimized indexes for fast queries
- Unread message counts view

## Troubleshooting

### Error: Column already exists
If you get errors about columns already existing, you've already imported this schema. Skip that ALTER TABLE statement.

### Error: Cannot add foreign key constraint
Make sure you've imported the base `messaging_schema.sql` first. The tables `users`, `chat_groups`, and `chat_messages` must exist.

### Event Scheduler Issues
Just use `whatsapp_features_schema_no_events.sql` and handle cleanup in your application code.

## Need Help?

Check the main integration guide: `INTEGRATION_GUIDE.md` in the project root.

