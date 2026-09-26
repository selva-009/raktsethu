-- ── Password Reset (by Email) Migration ─────────────────────────────
-- Run this ONCE in phpMyAdmin (InfinityFree control panel → phpMyAdmin
-- → select your database → SQL tab → paste → Go).
--
-- Adds the columns used by auth/forgot_password.php ("Email me a reset
-- link") and auth/reset_password.php. No existing data is changed.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS resetToken   VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS resetTokenAt DATETIME    DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_users_resetToken ON users (resetToken);
