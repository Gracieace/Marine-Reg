<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SELECT student_id FROM enrollments LIMIT 1");
$id = $stmt->fetchColumn();
echo $id;
