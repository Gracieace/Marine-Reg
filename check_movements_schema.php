<?php
require_once 'config/db.php';
$pdo = db_connect();
echo "--- learner_movements ---\n";
var_dump($pdo->query("DESCRIBE learner_movements")->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- school_years ---\n";
var_dump($pdo->query("DESCRIBE school_years")->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- enrollments ---\n";
var_dump($pdo->query("DESCRIBE enrollments")->fetchAll(PDO::FETCH_ASSOC));
