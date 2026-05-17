<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = db_connect();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $col = $stmt->fetch();
    if ($col) {
        echo "Column 'role' exists in 'users' table.\n";
        print_r($col);
    } else {
        echo "Column 'role' MISSING in 'users' table.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
