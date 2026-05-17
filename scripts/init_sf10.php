<?php
require_once __DIR__ . '/../config/db.php';

function initialize_sf10_schema($pdo) {
    // 1. Conduct Records (Character Building)
    $pdo->exec("CREATE TABLE IF NOT EXISTS conduct_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        school_year VARCHAR(20) NOT NULL,
        grade_level VARCHAR(50) NOT NULL,
        core_values TEXT NULL, -- JSON or serialized data for Maka-Diyos, Maka-tao, etc.
        remarks TEXT NULL,
        adviser_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_conduct (student_id, school_year)
    ) ENGINE=InnoDB");

    // 2. School History (Previous schools for SF10)
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        school_name VARCHAR(255) NOT NULL,
        school_id VARCHAR(50) NULL,
        district VARCHAR(100) NULL,
        division VARCHAR(100) NULL,
        region VARCHAR(100) NULL,
        grade_level VARCHAR(50) NOT NULL,
        school_year VARCHAR(20) NOT NULL,
        general_average DECIMAL(5,2) NULL,
        promotion_status VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 3. Transfer Records
    $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        transfer_type ENUM('IN', 'OUT') NOT NULL,
        date_of_transfer DATE NOT NULL,
        school_name VARCHAR(255) NOT NULL,
        reason TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 4. Awards & Recognition
    $pdo->exec("CREATE TABLE IF NOT EXISTS awards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        school_year VARCHAR(20) NOT NULL,
        award_name VARCHAR(255) NOT NULL,
        award_type VARCHAR(100) NULL, -- Academic, Leadership, etc.
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 5. SF10 Finalized Records (The "Master" Record)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sf10_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        status ENUM('Draft', 'Verified', 'Finalized', 'Locked') DEFAULT 'Draft',
        verified_by INT NULL,
        verified_at TIMESTAMP NULL,
        finalized_by INT NULL,
        finalized_at TIMESTAMP NULL,
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_sf10 (student_id)
    ) ENGINE=InnoDB");
}

// Run if called directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    $pdo = db_connect();
    initialize_sf10_schema($pdo);
    echo "SF10 Schema initialized successfully.";
}
