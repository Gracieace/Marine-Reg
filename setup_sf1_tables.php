<?php
require_once __DIR__ . '/config/db.php';
$pdo = db_connect();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf1_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            school_year VARCHAR(20) NOT NULL,
            grade_level VARCHAR(20) NOT NULL,
            section VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (teacher_id),
            INDEX (school_year, grade_level, section)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS sf1_student_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sf1_report_id INT NOT NULL,
            lrn VARCHAR(12) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100),
            sex ENUM('M', 'F') NOT NULL,
            birth_date DATE NOT NULL,
            age_as_of_oct31 INT,
            mother_tongue VARCHAR(100),
            ip_ethnicity VARCHAR(100),
            religion VARCHAR(100),
            house_no_street VARCHAR(255),
            barangay VARCHAR(100),
            municipality_city VARCHAR(100),
            province VARCHAR(100),
            father_last_name VARCHAR(100),
            father_first_name VARCHAR(100),
            father_middle_name VARCHAR(100),
            mother_last_name VARCHAR(100),
            mother_first_name VARCHAR(100),
            mother_middle_name VARCHAR(100),
            guardian_name VARCHAR(255),
            guardian_relationship VARCHAR(100),
            contact_number VARCHAR(20),
            learning_modality VARCHAR(100),
            remarks_code VARCHAR(10),
            remarks TEXT,
            FOREIGN KEY (sf1_report_id) REFERENCES sf1_reports(id) ON DELETE CASCADE,
            INDEX (lrn)
        ) ENGINE=InnoDB;

        CREATE TABLE IF NOT EXISTS sf1_summary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sf1_report_id INT NOT NULL,
            total_male INT DEFAULT 0,
            total_female INT DEFAULT 0,
            total_combined INT DEFAULT 0,
            registered_male_bosy INT DEFAULT 0,
            registered_female_bosy INT DEFAULT 0,
            registered_total_bosy INT DEFAULT 0,
            registered_male_eosy INT DEFAULT 0,
            registered_female_eosy INT DEFAULT 0,
            registered_total_eosy INT DEFAULT 0,
            prepared_by_name VARCHAR(255),
            prepared_bosy_date DATE,
            prepared_eosy_date DATE,
            certified_by_name VARCHAR(255),
            certified_bosy_date DATE,
            certified_eosy_date DATE,
            FOREIGN KEY (sf1_report_id) REFERENCES sf1_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    ");
    echo "SF1 tables initialized successfully!\n";
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
