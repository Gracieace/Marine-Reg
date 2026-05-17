<?php
require_once 'config/db.php';
$pdo = db_connect();
$tables = $pdo->query("SHOW TABLES LIKE 'sf4%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables found: " . implode(', ', $tables) . "\n";
