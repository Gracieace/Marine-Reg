<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db_connect();

    // Create SF3 reports table (Books Issued)
    $sql1 = 'CREATE TABLE IF NOT EXISTS sf3_reports (
id INT AUTO_INCREMENT PRIMARY KEY,
teacher_id INT NOT NULL,
school_year VARCHAR(20) NOT NULL,
grade_level VARCHAR(50) NOT NULL,
section VARCHAR(100) NOT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
KEY idx_teacher_id (teacher_id),
KEY idx_school_year (school_year),
KEY idx_grade_section (grade_level, section),
UNIQUE KEY unique_sf3_report (teacher_id, school_year, grade_level, section)
) ENGINE=InnoDB';

    $pdo->exec($sql1);
    echo "Created sf3_reports table.<br>";

    // Create SF3 books table
    $sql2 = 'CREATE TABLE IF NOT EXISTS sf3_books (
id INT AUTO_INCREMENT PRIMARY KEY,
sf3_report_id INT NOT NULL,
student_id VARCHAR(50) NOT NULL,
student_name VARCHAR(200) NOT NULL,
sex ENUM("M", "F") NOT NULL,
math VARCHAR(50) NULL,
science VARCHAR(50) NULL,
english VARCHAR(50) NULL,
filipino VARCHAR(50) NULL,
ap VARCHAR(50) NULL,
mapeh VARCHAR(50) NULL,
tle VARCHAR(50) NULL,
values_ed VARCHAR(50) NULL,
computer VARCHAR(50) NULL,
research VARCHAR(50) NULL,
remarks VARCHAR(500) NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
FOREIGN KEY (sf3_report_id) REFERENCES sf3_reports(id) ON DELETE CASCADE,
KEY idx_sf3_report_id (sf3_report_id),
KEY idx_student_id (student_id),
UNIQUE KEY unique_sf3_student (sf3_report_id, student_id)
) ENGINE=InnoDB';

    $pdo->exec($sql2);
    echo "Created sf3_books table.<br>";

    echo "Done.";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}