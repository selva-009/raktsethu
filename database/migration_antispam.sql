-- ============================================
-- RaktSethu — Anti-Spam Registration Migration
-- Run this in phpMyAdmin
-- ============================================

-- Track registration attempts for rate limiting (3 per IP per hour)
CREATE TABLE IF NOT EXISTS registration_attempts (
    attemptId  INT AUTO_INCREMENT PRIMARY KEY,
    ipAddress  VARCHAR(45) NOT NULL,
    attemptedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auto-cleanup: optionally delete attempts older than 24 hours
-- This keeps the table small. Run manually or via cron.
