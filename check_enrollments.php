<?php
require 'config/db.php';
$pdo = db_connect();
echo "--- DISTINCT Grade Levels in Enrollments ---\n";
$stmt = $pdo->query('SELECT DISTINCT grade_level FROM enrollments');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

echo "\n--- DISTINCT Sections in Enrollments ---\n";
$stmt = $pdo->query('SELECT DISTINCT section FROM enrollments');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

echo "\n--- DISTINCT School Years in Enrollments ---\n";
$stmt = $pdo->query('SELECT DISTINCT school_year FROM enrollments');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

echo "\n--- Recent Enrollments Sample ---\n";
$stmt = $pdo->query('SELECT student_name, grade_level, section, school_year FROM enrollments ORDER BY id DESC LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
