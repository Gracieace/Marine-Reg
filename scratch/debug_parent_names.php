<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->prepare("SELECT lrn, last_name, first_name, father_last, father_first, father_middle, mother_last, mother_first, mother_middle FROM registrations WHERE father_last LIKE '%Cabrera%' OR mother_last LIKE '%Magpayo%' LIMIT 10");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($results);
