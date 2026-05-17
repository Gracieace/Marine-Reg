<?php
$pdo = new PDO('mysql:host=localhost;dbname=mmfsl_db', 'root', '');
$stmt = $pdo->prepare("SELECT e.*, r.sex, r.age, r.lrn as reg_lrn FROM enrollments e LEFT JOIN registrations r ON e.registration_id = r.id WHERE e.student_id = ?");
$stmt->execute(['2026-0003']);
$res = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($res);
