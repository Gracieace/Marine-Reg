<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = db_connect();
    $stmt = $pdo->query('DESCRIBE registrations');
    $columns = [];
    foreach ($stmt as $row) {
        $columns[] = $row['Field'];
    }
    echo implode(", ", $columns);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
