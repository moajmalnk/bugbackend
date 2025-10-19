-- Add phone column to users table if it doesn't exist
ALTER TABLE users
ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL;
-- Add index for phone column if it doesn't exist
CREATE INDEX IF NOT EXISTS idx_users_phone ON users(phone);
-- Update existing users with a default phone number (optional)
-- UPDATE users SET phone = '+918848676627' WHERE phone IS NULL;