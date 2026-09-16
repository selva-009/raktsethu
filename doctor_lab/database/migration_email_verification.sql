-- ── Email Verification Migration ──────────────────────────────────
-- Run this ONCE in phpMyAdmin (InfinityFree control panel → phpMyAdmin
-- → select your database → SQL tab → paste → Go).
--
-- 1. Adds email verification columns to the users table.
-- 2. Marks ALL EXISTING accounts (including your admin) as already
--    verified, so nobody gets locked out after the migration.
--    Only NEW registrations will need to verify their email.

ALTER TABLE users
  ADD COLUMN emailVerified TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN verificationToken VARCHAR(64) DEFAULT NULL,
  ADD COLUMN verificationTokenAt DATETIME DEFAULT NULL;

UPDATE users SET emailVerified = 1;
