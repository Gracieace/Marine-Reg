<?php
require 'config/db.php';
$pdo = db_connect();
echo "--- sf3_student_books ---\n";
try {
    $stmt = $pdo->query('DESCRIBE sf3_student_books');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "--- sf3_books_inventory ---\n";
try {
    $stmt = $pdo->query('DESCRIBE sf3_books_inventory');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

echo "--- sf3_reports ---\n";
try {
    $stmt = $pdo->query('DESCRIBE sf3_reports');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
