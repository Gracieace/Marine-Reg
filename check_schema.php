<?php
require_once __DIR__ . '/config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SELECT DISTINCT action_taken FROM sf5_students");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
