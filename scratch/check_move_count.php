<?php
require_once 'config/db.php';
$pdo = db_connect();
$count = $pdo->query("SELECT COUNT(*) FROM student_movements")->fetchColumn();
echo "Count: " . $count;
?>
