<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("DESCRIBE curriculum");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
