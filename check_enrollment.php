<?php
require_once __DIR__ . '/config/db.php';
try {
    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE school_year = '2026-2027'");
    $stmt->execute();
    echo "Enrollments count for 2026-2027: " . $stmt->fetchColumn() . "\n";
    
    $stmt = $pdo->prepare("SELECT DISTINCT grade_level, section FROM enrollments WHERE school_year = '2026-2027'");
    $stmt->execute();
    echo "Found sections: \n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $row['grade_level'] . " | " . $row['section'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
