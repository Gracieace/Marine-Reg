<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = db_connect();
    echo "Connected to database.\n";
    initialize_schema($pdo);
    echo "Schema initialized successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
