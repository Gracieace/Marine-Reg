<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();
try {
    $pdo->query("SELECT 1 FROM attendance LIMIT 1");
    echo "TABLE ATTENDANCE EXISTS";
} catch (Exception $e) {
    echo "TABLE ATTENDANCE MISSING: " . $e->getMessage();
}
