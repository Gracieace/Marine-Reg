-- Student School ID & QR Re-Enrollment System Tables

CREATE TABLE IF NOT EXISTS school_ids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    id_number VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('Active', 'Lost', 'Expired', 'Revoked') DEFAULT 'Active',
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_student_id (student_id),
    KEY idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qr_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL UNIQUE,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_token (student_id, token)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reenrollment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    scanned_by INT NOT NULL,
    old_enrollment_id INT NULL,
    new_enrollment_id INT NULL,
    school_year VARCHAR(20) NOT NULL,
    status ENUM('Success', 'Failed', 'Pending') DEFAULT 'Success',
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_student_id (student_id),
    KEY idx_school_year (school_year)
) ENGINE=InnoDB;
