-- ============================================
-- RaktSethu — Donor Engagement Migration
-- Run this in phpMyAdmin after the license verification migration
-- ============================================

-- Add isAvailable toggle to donors (default 1 = available)
ALTER TABLE donors ADD COLUMN isAvailable TINYINT(1) NOT NULL DEFAULT 1;

-- Add priority flag to blood_requests (0 = normal, 1 = priority donor)
ALTER TABLE blood_requests ADD COLUMN isPriority TINYINT(1) NOT NULL DEFAULT 0;

-- Donation history table — records each completed donation
CREATE TABLE IF NOT EXISTS donation_history (
    donationId   INT AUTO_INCREMENT PRIMARY KEY,
    donorId      INT NOT NULL,
    patientName  VARCHAR(255) DEFAULT NULL,
    bloodGroup   VARCHAR(5)  NOT NULL,
    units        INT NOT NULL DEFAULT 1,
    donationDate DATE NOT NULL,
    verified     TINYINT(1) NOT NULL DEFAULT 0,
    verifiedBy   INT DEFAULT NULL,
    verifiedAt   DATETIME DEFAULT NULL,
    createdAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donorId) REFERENCES donors(donorId) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
