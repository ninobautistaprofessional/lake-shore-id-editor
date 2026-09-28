-- Migration: Lost ID Requests & Receipt Uploads
-- Run this SQL to add the new feature for lost ID reprint requests

CREATE TABLE IF NOT EXISTS lost_id_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_name VARCHAR(150) NOT NULL,
  id_type ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH') NOT NULL DEFAULT 'COLLEGE',
  course VARCHAR(255) DEFAULT '',
  grade_level VARCHAR(20) DEFAULT '',
  section_name VARCHAR(100) DEFAULT '',
  student_number VARCHAR(100) DEFAULT '',
  lrn VARCHAR(100) DEFAULT '',
  reference_card_id INT UNSIGNED NULL DEFAULT NULL,
  status ENUM('pending','approved','reprinted','rejected') NOT NULL DEFAULT 'pending',
  receipt_path VARCHAR(255) DEFAULT '',
  notes TEXT DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_student_name (student_name)
);
