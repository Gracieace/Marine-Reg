<?php
require_once 'config/db.php';
$pdo = db_connect();
echo "--- sf5_students ---\n";
print_r($pdo->query("DESC sf5_students")->fetchAll());
