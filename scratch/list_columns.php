<?php
require_once 'config/db.php';
$pdo = db_connect();
$stmt = $pdo->prepare("SHOW COLUMNS FROM registrations");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($results as $r) {
    echo $r['Field'] . "\n";
}
