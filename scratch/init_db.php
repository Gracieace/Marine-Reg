<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = db_connect();
    initialize_schema($pdo);
    echo "Schema initialized successfully.";
} catch (Exception $e) {
    echo "Error initializing schema: " . $e->getMessage();
}
