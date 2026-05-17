<?php
require_once 'config/db.php';
$pdo = db_connect();
echo "--- sf9_grades ---\n";
print_r($pdo->query("DESC sf9_grades")->fetchAll());
echo "--- enrollments ---\n";
print_r($pdo->query("DESC enrollments")->fetchAll());
