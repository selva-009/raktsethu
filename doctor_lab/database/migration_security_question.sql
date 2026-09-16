-- ============================================
-- RaktSethu — Security Question Migration
-- Run this in phpMyAdmin
-- ============================================

-- Add security question columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS securityQuestion VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS securityAnswer VARCHAR(255) DEFAULT NULL;

-- Note: For existing users who registered before this migration,
-- their securityQuestion and securityAnswer will be NULL.
-- They should contact admin to reset their password, or re-register.
