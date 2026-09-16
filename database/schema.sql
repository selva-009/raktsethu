-- ============================================================
-- Thalassemia Blood Support System (RaktSethu)
-- Database Schema — locked at nine tables (see Chapter 3, Fig. 3.7)
-- Engine: InnoDB, Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS thalassemia_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE thalassemia_db;

-- ------------------------------------------------------------
-- 1. users — central authentication record for all four roles
--    Admin is a role on this table, not a separate entity.
-- ------------------------------------------------------------
CREATE TABLE users (
    userId       INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(100) NOT NULL UNIQUE,
    passwordHash VARCHAR(255) NOT NULL,
    role         ENUM('patient','donor','doctor_lab','admin') NOT NULL,
    createdAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. patients — role-specific profile for patient users
-- ------------------------------------------------------------
CREATE TABLE patients (
    patientId       INT AUTO_INCREMENT PRIMARY KEY,
    userId          INT NOT NULL,
    bloodGroup      VARCHAR(5) NOT NULL,
    thalassemiaType VARCHAR(50),
    address         VARCHAR(255),
    location        VARCHAR(100),  -- city placeholder; geocoded lat,long once Nominatim is wired in
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE,
    INDEX idx_patient_bloodgroup (bloodGroup)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. donors — role-specific profile for donor users
--    90-day eligibility rule is enforced in application logic
--    (eligibilityExpiry = lastDonationDate + 90 days).
-- ------------------------------------------------------------
CREATE TABLE donors (
    donorId           INT AUTO_INCREMENT PRIMARY KEY,
    userId            INT NOT NULL,
    bloodGroup        VARCHAR(5) NOT NULL,
    lastDonationDate  DATE NULL,
    eligibilityExpiry DATE NULL,
    verified          BOOLEAN NOT NULL DEFAULT FALSE,
    location          VARCHAR(100),
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE,
    INDEX idx_donor_bloodgroup (bloodGroup),
    INDEX idx_donor_location (location)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. doctors_labs — role-specific profile for doctor/lab users
-- ------------------------------------------------------------
CREATE TABLE doctors_labs (
    doctorId     INT AUTO_INCREMENT PRIMARY KEY,
    userId       INT NOT NULL,
    labLicenseNo VARCHAR(50) NOT NULL UNIQUE,
    affiliation  VARCHAR(150),
    approved     BOOLEAN NOT NULL DEFAULT FALSE, -- admin approves lab before it can verify reports
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. blood_requests — urgent requests raised by patients
--    Business rule: one active (pending/matched) request per patient
--    is enforced in application logic (engine/matching_engine.php).
-- ------------------------------------------------------------
CREATE TABLE blood_requests (
    requestId   INT AUTO_INCREMENT PRIMARY KEY,
    patientId   INT NOT NULL,
    bloodGroup  VARCHAR(5) NOT NULL,
    unitsNeeded INT NOT NULL DEFAULT 1,
    status      ENUM('pending','matched','fulfilled','expired') NOT NULL DEFAULT 'pending',
    createdAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt   DATETIME NULL,
    FOREIGN KEY (patientId) REFERENCES patients(patientId) ON DELETE CASCADE,
    INDEX idx_request_status (status)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. donor_matches — match records between requests and donors
--    48-hour auto-expiry rule: responseDeadline = matchedAt + 48h.
-- ------------------------------------------------------------
CREATE TABLE donor_matches (
    matchId          INT AUTO_INCREMENT PRIMARY KEY,
    requestId        INT NOT NULL,
    donorId          INT NOT NULL,
    status           ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
    matchedAt         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responseDeadline DATETIME NOT NULL,
    contactRevealed  BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (requestId) REFERENCES blood_requests(requestId) ON DELETE CASCADE,
    FOREIGN KEY (donorId) REFERENCES donors(donorId) ON DELETE CASCADE,
    INDEX idx_match_status (status)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. notifications — email / SMS / in-app alerts
--    Also carries donor test-request routing to admin-approved
--    labs (see Chapter 3, 3.7) instead of a dedicated table.
-- ------------------------------------------------------------
CREATE TABLE notifications (
    notifId INT AUTO_INCREMENT PRIMARY KEY,
    userId  INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    channel ENUM('email','sms','in-app') NOT NULL DEFAULT 'in-app',
    sentAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    isRead  BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. donor_reports — pathology reports uploaded by donors
-- ------------------------------------------------------------
CREATE TABLE donor_reports (
    reportId   INT AUTO_INCREMENT PRIMARY KEY,
    donorId    INT NOT NULL,
    fileUrl    VARCHAR(255) NOT NULL,
    uploadDate DATE NOT NULL DEFAULT (CURRENT_DATE),
    expiryDate DATE NULL,
    FOREIGN KEY (donorId) REFERENCES donors(donorId) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. verifications — doctor/lab review of donor reports
-- ------------------------------------------------------------
CREATE TABLE verifications (
    verificationId INT AUTO_INCREMENT PRIMARY KEY,
    reportId       INT NOT NULL,
    doctorId       INT NOT NULL,
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    remarks        VARCHAR(255),
    verifiedOn     DATETIME NULL,
    FOREIGN KEY (reportId) REFERENCES donor_reports(reportId) ON DELETE CASCADE,
    FOREIGN KEY (doctorId) REFERENCES doctors_labs(doctorId) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Initial admin account: do NOT hardcode a password hash here.
-- Run database/seed_admin.php once (php seed_admin.php) after
-- importing this schema — it prompts for a password, hashes it
-- with PHP's own password_hash(), and inserts the admin row.
-- ------------------------------------------------------------
