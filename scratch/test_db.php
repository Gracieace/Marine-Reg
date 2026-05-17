<?php
require_once 'config/db.php';
try {
    $pdo = db_connect();
    $stmt = $pdo->query("SELECT 1 FROM sf9_reports LIMIT 1");
    echo "sf9_reports exists.\n";
    $stmt = $pdo->query("SELECT 1 FROM sf9_grades LIMIT 1");
    echo "sf9_grades exists.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
