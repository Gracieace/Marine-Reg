<?php
require 'config/db.php';
$pdo = db_connect();

$students = ['2026-0002', '2026-0003'];
foreach ($students as $lrn) {
    echo "\nLRN: $lrn\n";
    $stmt = $pdo->prepare("SELECT d.id, d.student_id, d.status, d.section_id FROM textbook_distributions d WHERE d.student_id = ?");
    $stmt->execute([$lrn]);
    $dists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Distributions found: " . count($dists) . "\n";
    print_r($dists);
}

// Check enrollments for these LRNS
foreach ($students as $lrn) {
    echo "\nEnrollments for LRN: $lrn\n";
    $stmt = $pdo->prepare("SELECT student_id, section, grade_level, school_year FROM enrollments WHERE student_id = ?");
    $stmt->execute([$lrn]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}
