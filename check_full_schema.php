<?php
require_once 'config/db.php';
$pdo = db_connect();
echo "--- enrollments ---\n";
var_dump($pdo->query("DESCRIBE enrollments")->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- student_movements ---\n";
var_dump($pdo->query("DESCRIBE student_movements")->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- school_years ---\n";
var_dump($pdo->query("SELECT * FROM school_years")->fetchAll(PDO::FETCH_ASSOC));
