<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("DESC enrollments");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
