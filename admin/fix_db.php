<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db_connect();
    echo "Connected to database.\n";

    initialize_schema($pdo);
    echo "Schema initialization function called.\n";

    // Check if tables exist
    $tables = ['sf2_reports', 'sf2_daily_attendance', 'sf2_student_records', 'sf3_reports', 'sf3_books'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "Table '$table' exists.\n";
        } else {
            echo "Error: Table '$table' does not exist!\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
