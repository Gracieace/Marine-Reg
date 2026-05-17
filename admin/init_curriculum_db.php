<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db_connect();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create curriculum_programs table
    $sqlPrograms = "CREATE TABLE IF NOT EXISTS curriculum_programs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_code VARCHAR(50) NOT NULL,
        program_name VARCHAR(100) NOT NULL,
        program_type VARCHAR(50) NOT NULL,
        grade_levels VARCHAR(100) NOT NULL,
        program_semester VARCHAR(50) NULL,
        duration_years DECIMAL(3,1) DEFAULT 1.0,
        total_units DECIMAL(5,2) DEFAULT 0.00,
        description TEXT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sqlPrograms);
    echo "Table 'curriculum_programs' created or already exists.\n";

    // Create curriculum table with Foreign Key
    $sqlCurriculum = "CREATE TABLE IF NOT EXISTS curriculum (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_id INT NOT NULL,
        subject_code VARCHAR(50) NOT NULL,
        subject_name VARCHAR(100) NOT NULL,
        grade_level VARCHAR(50) NOT NULL,
        semester VARCHAR(50) NULL,
        units DECIMAL(3,1) DEFAULT 0.0,
        description TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (program_id) REFERENCES curriculum_programs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sqlCurriculum);
    echo "Table 'curriculum' created or already exists.\n";

} catch (PDOException $e) {
    echo "Error creating tables: " . $e->getMessage() . "\n";
    exit(1);
}
?>