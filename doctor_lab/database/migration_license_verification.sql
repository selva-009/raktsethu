-- ============================================================
-- License Verification Migration
-- Run this in phpMyAdmin SQL tab to add license verification columns
-- and the license_verifications audit table.
-- ============================================================

-- Add new columns to doctors_labs for license verification
ALTER TABLE doctors_labs
    ADD COLUMN verification_status ENUM(
        'pending_verification',
        'approved',
        'rejected',
        'verification_failed',
        'manual_review'
    ) NOT NULL DEFAULT 'pending_verification' AFTER approved,
    ADD COLUMN license_state_council VARCHAR(100) NULL AFTER affiliation,
    ADD COLUMN license_year VARCHAR(10) NULL AFTER license_state_council,
    ADD COLUMN specialty VARCHAR(100) NULL AFTER license_year,
    ADD COLUMN verified_at DATETIME NULL AFTER specialty,
    ADD COLUMN verified_by_admin_id INT NULL AFTER verified_at,
    ADD COLUMN verification_notes TEXT NULL AFTER verified_by_admin_id;

-- New table: license_verifications — audit log of all verification attempts
CREATE TABLE IF NOT EXISTS license_verifications (
    verificationId   INT AUTO_INCREMENT PRIMARY KEY,
    doctorId         INT NOT NULL,
    license_number   VARCHAR(50) NOT NULL,
    doctor_name      VARCHAR(100) NOT NULL,
    state_council    VARCHAR(100),
    api_status       VARCHAR(50) NOT NULL,
    api_response     TEXT NULL,
    verified_by      INT NOT NULL,
    verified_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_decision   VARCHAR(50) NOT NULL,
    FOREIGN KEY (doctorId) REFERENCES doctors_labs(doctorId) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(userId) ON DELETE CASCADE,
    INDEX idx_license_verification_doctor (doctorId),
    INDEX idx_license_verification_status (api_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Set existing approved labs to 'approved' status
UPDATE doctors_labs SET verification_status = 'approved' WHERE approved = 1;
