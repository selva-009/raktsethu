-- Add is_read column to notifications table (for notification bell)
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read TINYINT(1) NOT NULL DEFAULT 0;
