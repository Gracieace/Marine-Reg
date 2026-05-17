<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();
$cols = $pdo->query("DESCRIBE sf4_rows")->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);
