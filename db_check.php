<?php
require_once 'config/db.php';
try {
    $pdo = db_connect();
    echo "COLUMNS IN enrollments:\n";
    $stmt = $pdo->query("DESCRIBE enrollments");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\nSAMPLE DATA FROM enrollments:\n";
    $stmt = $pdo->query("SELECT * FROM enrollments LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\nDISTINCT GRADES:\n";
    $stmt = $pdo->query("SELECT DISTINCT grade_level FROM enrollments");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

    echo "\nDISTINCT SECTIONS:\n";
    $stmt = $pdo->query("SELECT DISTINCT section FROM enrollments");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
