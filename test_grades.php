<?php
require 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query('SELECT DISTINCT grade_level FROM admin_books');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
