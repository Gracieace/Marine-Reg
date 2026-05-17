<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->query("SELECT id, lrn, last_name, first_name, father_last, father_first, father_middle, guardian_last, guardian_first, guardian_middle, guardian_name FROM registrations WHERE father_last = 'Cabrera' OR guardian_last LIKE '%Cabrera%' OR guardian_name LIKE '%Cabrera%' LIMIT 5");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>"; print_r($res); echo "</pre>";
?>
