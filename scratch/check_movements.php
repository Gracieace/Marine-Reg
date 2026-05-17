<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SELECT DISTINCT movement_type FROM student_movements");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
