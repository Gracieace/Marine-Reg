<?php
require 'config/db.php';
$pdo = db_connect();
$name = '%Jasmine%Cabrera%';
$stmt = $pdo->prepare('SELECT * FROM enrollments WHERE student_name LIKE ?');
$stmt->execute([$name]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Students found:\n";
print_r($students);

if (!empty($students)) {
    foreach ($students as $s) {
        $lrn = $s['student_id']; // Usually student_id is LRN
        $stmt = $pdo->prepare('SELECT d.id, b.title FROM textbook_distributions d JOIN admin_books b ON d.textbook_id = b.id WHERE d.student_id = ?');
        $stmt->execute([$lrn]);
        $dists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nDistributions for LRN $lrn:\n";
        print_r($dists);
    }
}
