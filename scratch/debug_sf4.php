<?php
require 'config/db.php';
$pdo = db_connect();
echo "SECTIONS:\n";
foreach($pdo->query('SELECT * FROM sections') as $r) print_r($r);
echo "\nENROLLMENTS:\n";
foreach($pdo->query('SELECT * FROM enrollments LIMIT 5') as $r) print_r($r);
echo "\nSF1 SUMMARY:\n";
foreach($pdo->query('SELECT * FROM sf1_summary LIMIT 5') as $r) print_r($r);
echo "\nSF2 SUMMARY:\n";
foreach($pdo->query('SELECT * FROM sf2_monthly_summary LIMIT 5') as $r) print_r($r);
