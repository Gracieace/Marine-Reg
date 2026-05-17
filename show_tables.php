<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SHOW TABLES");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
?>
