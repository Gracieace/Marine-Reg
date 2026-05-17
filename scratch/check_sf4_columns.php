<?php
require 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("DESCRIBE sf4_rows");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
