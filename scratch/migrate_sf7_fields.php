<?php
require_once 'config/db.php';
$pdo = db_connect();

$columns = [
    'tin' => 'VARCHAR(50) NULL',
    'fund_source' => 'VARCHAR(100) NULL',
    'appointment_status' => 'VARCHAR(100) NULL',
    'educational_degree' => 'VARCHAR(255) NULL',
    'major_specialization' => 'VARCHAR(255) NULL',
    'minor_specialization' => 'VARCHAR(255) NULL',
    'salary_grade' => 'INT NULL',
    'position_title' => 'VARCHAR(150) NULL'
];

foreach ($columns as $col => $type) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN $col $type");
        echo "Added column: $col\n";
    } catch (Exception $e) {
        echo "Column $col already exists or error: " . $e->getMessage() . "\n";
    }
}
?>
