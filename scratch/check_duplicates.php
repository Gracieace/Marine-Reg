<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SELECT student_name, student_id, COUNT(*) as count FROM enrollments GROUP BY student_name, student_id HAVING count > 1");
print_r($stmt->fetchAll());
