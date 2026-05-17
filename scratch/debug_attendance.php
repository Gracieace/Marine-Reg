<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();

echo "--- Position Types --- \n";
$stmt = $pdo->query("SELECT DISTINCT position_type FROM position_assignments");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

echo "\n--- My Assignments ($teacher_id) --- \n";
$stmt = $pdo->prepare("SELECT * FROM position_assignments WHERE user_id = ?");
$stmt->execute([$teacher_id]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
