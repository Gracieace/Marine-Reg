<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SELECT DISTINCT remarks_code, remarks FROM sf1_student_records WHERE remarks_code IS NOT NULL OR remarks IS NOT NULL LIMIT 20");
print_r($stmt->fetchAll());
